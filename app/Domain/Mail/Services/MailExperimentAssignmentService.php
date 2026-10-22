<?php

namespace App\Domain\Mail\Services;

use App\Domain\Campaigns\DTOs\EnrollmentCandidate;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Services\EnrollPeopleInCampaign;
use App\Domain\Mail\DTOs\AssignmentDecision;
use App\Domain\Mail\Enums\RecipientMailboxProvider;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MailExperiment;
use App\Models\MailExperimentAssignment;
use App\Models\MailExperimentCohort;
use App\Models\Person;
use App\Models\SuppressionEntry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class MailExperimentAssignmentService
{
    /** @var list<string> */
    private const ROLE_LOCAL_PARTS = [
        'abuse',
        'admin',
        'do-not-contact',
        'donotcontact',
        'internal',
        'mailer-daemon',
        'no-reply',
        'noreply',
        'postmaster',
        'seed',
        'test',
    ];

    public function __construct(
        private RecipientMailboxProviderClassifier $classifier,
        private DeterministicStratifiedAssignment $assignment,
    ) {}

    /**
     * Evaluate, classify, deterministically assign, and persist a preparation
     * wave. Excluded candidates are intentionally persisted as evidence with a
     * null cohort.
     *
     * @param  iterable<int, mixed>  $candidates
     * @param  array<string, mixed>  $approvalEvidence
     * @return Collection<int, MailExperimentAssignment>
     */
    public function handle(
        MailExperiment $experiment,
        iterable $candidates,
        array $approvalEvidence = [],
    ): Collection {
        $materialisedCandidates = [];
        foreach ($candidates as $candidate) {
            $materialisedCandidates[] = $candidate;
        }

        return DB::transaction(function () use ($experiment, $materialisedCandidates, $approvalEvidence): Collection {
            $locked = MailExperiment::query()
                ->whereKey($experiment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $locked->assertMutable();

            if (! in_array($locked->statusValue(), ['draft', 'ready'], true)) {
                throw new LogicException('Only draft or ready mail experiments can be prepared.');
            }

            $cohorts = $locked->cohorts()->orderBy('key')->get();
            if ($cohorts->count() < 2) {
                throw new LogicException('At least two cohorts are required before preparing assignments.');
            }

            if ($locked->assignments()->exists()) {
                $this->assertSameAssignmentSnapshot($locked, $materialisedCandidates, $approvalEvidence);

                return $locked->assignments()->orderBy('id')->get();
            }

            $inputSnapshot = $this->inputSnapshot($locked, $materialisedCandidates, $approvalEvidence);
            $inputSnapshotHash = $this->inputSnapshotHash($inputSnapshot);
            $candidateRows = $this->prepareCandidates($locked, $materialisedCandidates, $approvalEvidence);
            $decisions = $this->assignment->assign(
                (string) $locked->getAttribute('randomisation_seed'),
                $candidateRows,
                $cohorts->all(),
                $this->targetAssignmentsPerCohort($locked),
            );

            $recipientIds = $this->enrolCampaignRecipients($locked, $cohorts, $decisions);
            $cohortByKey = $cohorts->keyBy('key');
            $persisted = new Collection;

            $locked->forceFill([
                'assignment_snapshot' => $inputSnapshot,
                'assignment_snapshot_hash' => $inputSnapshotHash,
            ])->save();

            foreach ($decisions as $decision) {
                $cohort = $decision->cohortKey === null
                    ? null
                    : $cohortByKey->get($decision->cohortKey);

                if ($decision->cohortKey !== null && ! $cohort instanceof MailExperimentCohort) {
                    throw new LogicException('Assignment references an unknown experiment cohort.');
                }

                $campaignId = $cohort?->getAttribute('campaign_id');
                $recipientId = $campaignId === null
                    ? null
                    : ($recipientIds[(int) $campaignId][$decision->emailNormalized] ?? null);

                $row = new MailExperimentAssignment;
                $row->forceFill([
                    'mail_experiment_id' => $locked->id,
                    'mail_experiment_cohort_id' => $cohort?->id,
                    'workspace_id' => $locked->workspace_id,
                    'person_id' => $decision->personId,
                    'account_id' => $decision->accountId,
                    'campaign_id' => $campaignId,
                    'campaign_recipient_id' => $recipientId,
                    'email_normalized' => $decision->emailNormalized,
                    'recipient_domain' => $decision->recipientDomain,
                    'recipient_provider' => $decision->recipientProvider,
                    'stratum_key' => $decision->stratumKey,
                    'assignment_key' => $decision->assignmentKey,
                    'provider_mx_snapshot' => $decision->providerMxSnapshot,
                    'assignment_snapshot_hash' => $inputSnapshotHash,
                    'assigned_at' => now(),
                    'exclusion_reason' => $decision->exclusionReason,
                ])->save();

                $persisted->push($row);
            }

            $locked->forceFill(['status' => 'ready'])->save();

            return $persisted;
        });
    }

    /**
     * @param  iterable<int, mixed>  $candidates
     * @param  array<string, mixed>  $approvalEvidence
     * @return Collection<int, MailExperimentAssignment>
     */
    public function prepare(
        MailExperiment $experiment,
        iterable $candidates,
        array $approvalEvidence = [],
    ): Collection {
        return $this->handle($experiment, $candidates, $approvalEvidence);
    }

    /**
     * Return decisions without persisting them. This is used by dry-run
     * preparation and gives operators the same exact values as persistence.
     *
     * @param  iterable<int, mixed>  $candidates
     * @param  array<string, mixed>  $approvalEvidence
     * @return list<AssignmentDecision>
     */
    public function preview(
        MailExperiment $experiment,
        iterable $candidates,
        array $approvalEvidence = [],
    ): array {
        $experiment->assertMutable();
        $cohorts = $experiment->cohorts()->orderBy('key')->get();

        if ($cohorts->count() < 2) {
            throw new LogicException('At least two cohorts are required before preparing assignments.');
        }

        return $this->assignment->assign(
            (string) $experiment->getAttribute('randomisation_seed'),
            $this->prepareCandidates($experiment, $candidates, $approvalEvidence),
            $cohorts->all(),
            $this->targetAssignmentsPerCohort($experiment),
        );
    }

    /**
     * Build the canonical, replayable preparation input.  The audience is a
     * sorted set of normalised candidate records plus the provider/MX evidence
     * used for stratification; candidate iteration order is intentionally not
     * part of the snapshot.
     *
     * @param  iterable<int, mixed>  $candidates
     * @param  array<string, mixed>  $approvalEvidence
     * @return array<string, mixed>
     */
    public function inputSnapshot(
        MailExperiment $experiment,
        iterable $candidates,
        array $approvalEvidence = [],
    ): array {
        $workspaceId = (int) $experiment->getAttribute('workspace_id');
        $configuration = $experiment->getAttribute('configuration');
        $configuration = is_array($configuration) ? $configuration : [];
        $eligibility = is_array($configuration['eligibility'] ?? null)
            ? $configuration['eligibility']
            : [];
        $storedApprovalEvidence = is_array($configuration['approval_evidence'] ?? null)
            ? $configuration['approval_evidence']
            : [];
        $effectiveApprovalEvidence = [...$storedApprovalEvidence, ...$approvalEvidence];
        $audience = [];

        foreach ($candidates as $candidate) {
            $row = $this->candidateArray($candidate);
            $this->assertCandidateWorkspace($row, $workspaceId);

            $email = EmailNormalizer::normalize((string) ($row['email_normalized'] ?? $row['email_address'] ?? $row['email'] ?? ''));
            $row['email_normalized'] = $email;
            $row['email_address'] = EmailNormalizer::normalize((string) ($row['email_address'] ?? $row['email'] ?? $email));

            // `email`/`provider`/`mx` are accepted input aliases.  Store one
            // canonical representation so harmless caller formatting changes
            // cannot turn a valid retry into a new preparation wave.
            $classification = $this->classification($row);
            $row['recipient_provider'] = $classification['provider']->value;
            $row['provider_mx_snapshot'] = $classification['mx'];
            $row['stratum_key'] = trim((string) ($row['stratum_key'] ?? $classification['provider']->value));
            unset($row['email'], $row['provider'], $row['mx']);
            $row = $this->canonicalInputValue($row);

            $audience[] = [
                'candidate' => $row,
                'recipient_provider' => $classification['provider']->value,
                'provider_mx_snapshot' => $this->canonicalInputValue($classification['mx']),
            ];
        }

        usort($audience, function (array $left, array $right): int {
            return $this->canonicalInputJson($left) <=> $this->canonicalInputJson($right);
        });

        return $this->canonicalInputValue([
            'schema_version' => 1,
            // Never persist the seed itself.  Its digest still binds the
            // deterministic assignment input and detects a seed replacement
            // on a retry without weakening the existing encrypted-at-rest
            // protection.
            'randomisation_seed_hash' => hash('sha256', (string) $experiment->getAttribute('randomisation_seed')),
            // The approved sample target and ramp are part of the immutable
            // preparation input. A retry or a frozen launch must never reuse
            // assignments produced under a different sampling plan.
            'target_assignments_per_cohort' => $this->targetAssignmentsPerCohort($experiment),
            'ramp' => $this->rampConfiguration($configuration),
            'cohorts' => $experiment->cohorts()
                ->orderBy('key')
                ->get()
                ->map(fn (MailExperimentCohort $cohort): array => [
                    'id' => (int) $cohort->getKey(),
                    'key' => (string) $cohort->getAttribute('key'),
                    'domain_strategy' => $cohort->getAttribute('domain_strategy') instanceof \BackedEnum
                        ? $cohort->getAttribute('domain_strategy')->value
                        : (string) $cohort->getAttribute('domain_strategy'),
                ])
                ->values()
                ->all(),
            'eligibility' => $this->canonicalEligibility($eligibility),
            // Generated confirmation timestamps are audit metadata, not a
            // change to the approval decision.  The stable evidence is what
            // binds a retry to the original reviewed audience.
            'approval_evidence' => $this->stableApprovalEvidence($effectiveApprovalEvidence),
            'audience' => $audience,
        ]);
    }

    /** @param array<string, mixed> $snapshot */
    public function inputSnapshotHash(array $snapshot): string
    {
        return hash('sha256', $this->canonicalInputJson($snapshot));
    }

    /**
     * Assert that the rule/evidence portion of a persisted assignment
     * snapshot still matches the current experiment configuration.  The full
     * audience comparison requires the candidate iterable and is performed by
     * handle() on preparation retries.
     */
    public function assertPersistedInputsCurrent(MailExperiment $experiment): void
    {
        $snapshot = $experiment->getAttribute('assignment_snapshot');
        $storedHash = $experiment->getAttribute('assignment_snapshot_hash');
        if (! is_array($snapshot) || ! is_string($storedHash) || $storedHash === '') {
            return;
        }

        $configuration = $experiment->getAttribute('configuration');
        $configuration = is_array($configuration) ? $configuration : [];
        $currentRules = is_array($configuration['eligibility'] ?? null)
            ? $configuration['eligibility']
            : [];
        $currentEvidence = is_array($configuration['approval_evidence'] ?? null)
            ? $configuration['approval_evidence']
            : [];
        $currentCohorts = $experiment->cohorts()
            ->orderBy('key')
            ->get()
            ->map(fn (MailExperimentCohort $cohort): array => [
                'id' => (int) $cohort->getKey(),
                'key' => (string) $cohort->getAttribute('key'),
                'domain_strategy' => $cohort->getAttribute('domain_strategy') instanceof \BackedEnum
                    ? $cohort->getAttribute('domain_strategy')->value
                    : (string) $cohort->getAttribute('domain_strategy'),
            ])
            ->values()
            ->all();

        if ($this->canonicalInputJson($snapshot['eligibility'] ?? [])
            !== $this->canonicalInputJson($this->canonicalEligibility($currentRules))
            || $this->canonicalInputJson($snapshot['approval_evidence'] ?? [])
            !== $this->canonicalInputJson($this->stableApprovalEvidence($currentEvidence))
            || ($snapshot['randomisation_seed_hash'] ?? null)
                !== hash('sha256', (string) $experiment->getAttribute('randomisation_seed'))
            || ($snapshot['target_assignments_per_cohort'] ?? null)
                !== $this->targetAssignmentsPerCohort($experiment)
            || $this->canonicalInputJson($snapshot['ramp'] ?? [])
                !== $this->canonicalInputJson($this->rampConfiguration($configuration))
            || $this->canonicalInputJson($snapshot['cohorts'] ?? [])
                !== $this->canonicalInputJson($currentCohorts)) {
            throw new LogicException('The persisted assignment snapshot does not match the current experiment inputs.');
        }

        if ($this->inputSnapshotHash($snapshot) !== $storedHash) {
            throw new LogicException('The persisted assignment snapshot hash is invalid.');
        }

        $boundHashes = $experiment->assignments()
            ->whereNotNull('assignment_snapshot_hash')
            ->distinct()
            ->pluck('assignment_snapshot_hash')
            ->map(fn (mixed $hash): string => (string) $hash)
            ->all();
        $assignmentCount = $experiment->assignments()->count();
        $boundCount = $experiment->assignments()
            ->where('assignment_snapshot_hash', $storedHash)
            ->count();
        if ($boundHashes !== [] || $assignmentCount > 0) {
            if ($boundHashes !== [$storedHash] || $boundCount !== $assignmentCount) {
                throw new LogicException('Assignment rows are not bound to the persisted input snapshot.');
            }
        }
    }

    /**
     * @param  iterable<int, mixed>  $candidates
     * @param  array<string, mixed>  $approvalEvidence
     * @return list<array<string, mixed>>
     */
    private function prepareCandidates(
        MailExperiment $experiment,
        iterable $candidates,
        array $approvalEvidence = [],
    ): array {
        $workspaceId = (int) $experiment->getAttribute('workspace_id');
        $configuration = $experiment->getAttribute('configuration');
        $configuration = is_array($configuration) ? $configuration : [];
        $eligibility = is_array($configuration['eligibility'] ?? null)
            ? $configuration['eligibility']
            : [];
        $storedApprovalEvidence = is_array($configuration['approval_evidence'] ?? null)
            ? $configuration['approval_evidence']
            : [];
        $approvalEvidence = [...$storedApprovalEvidence, ...$approvalEvidence];
        $rows = [];

        foreach ($candidates as $candidate) {
            $row = $this->candidateArray($candidate);
            $this->assertCandidateWorkspace($row, $workspaceId);
            $email = EmailNormalizer::normalize((string) ($row['email_normalized'] ?? $row['email_address'] ?? $row['email'] ?? ''));
            $row['email_normalized'] = $email;
            $row['email_address'] = (string) ($row['email_address'] ?? $row['email'] ?? $email);

            // An explicit candidate-level rejection always wins. Global
            // confirmations fill only fields that a real Person record does
            // not carry, so review evidence cannot silently reverse a known
            // ineligible candidate.
            if (! array_key_exists('compliance_eligible', $row)
                && ($approvalEvidence['compliance_review_confirmed'] ?? null) === true) {
                $row['compliance_eligible'] = true;
            }
            if (! array_key_exists('legal_eligible', $row)
                && ($approvalEvidence['legal_review_confirmed'] ?? null) === true) {
                $row['legal_eligible'] = true;
            }

            $classification = $this->classification($row);
            $row['recipient_provider'] = $classification['provider']->value;
            $row['provider_mx_snapshot'] = $classification['mx'];
            $row['stratum_key'] = (string) ($row['stratum_key'] ?? $classification['provider']->value);

            $reason = $this->eligibilityReason($row, $workspaceId, $eligibility);
            if ($reason !== null) {
                $row['exclusion_reason'] = $reason;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function targetAssignmentsPerCohort(MailExperiment $experiment): ?int
    {
        $value = $experiment->getAttribute('target_assignments_per_cohort');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            throw new LogicException('The target assignments per cohort must be at least one.');
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function rampConfiguration(array $configuration): array
    {
        $ramp = $configuration['ramp'] ?? [];

        /** @var array<string, mixed> $canonical */
        $canonical = is_array($ramp) ? $this->canonicalInputValue($ramp) : [];

        return $canonical;
    }

    /**
     * Preparation is idempotent once assignments exist. Compare the complete
     * canonical input snapshot before any dynamic eligibility queries (the
     * first preparation may itself have created active campaign recipients).
     *
     * @param  iterable<int, mixed>  $candidates
     * @param  array<string, mixed>  $approvalEvidence
     */
    private function assertSameAssignmentSnapshot(
        MailExperiment $experiment,
        iterable $candidates,
        array $approvalEvidence = [],
    ): void {
        $expected = $this->inputSnapshot($experiment, $candidates, $approvalEvidence);
        $expectedHash = $this->inputSnapshotHash($expected);
        $stored = $experiment->getAttribute('assignment_snapshot');
        $storedHash = $experiment->getAttribute('assignment_snapshot_hash');

        if (! is_array($stored) || ! is_string($storedHash) || $storedHash === '') {
            throw new LogicException('Existing assignments are missing their immutable input snapshot.');
        }

        if (! hash_equals($storedHash, $expectedHash)
            || $this->canonicalInputJson($stored) !== $this->canonicalInputJson($expected)) {
            throw new LogicException('Preparation inputs differ from the existing assignment snapshot.');
        }

        $boundHashes = $experiment->assignments()
            ->whereNotNull('assignment_snapshot_hash')
            ->distinct()
            ->pluck('assignment_snapshot_hash')
            ->map(fn (mixed $hash): string => (string) $hash)
            ->all();
        $assignmentCount = $experiment->assignments()->count();
        $boundCount = $experiment->assignments()
            ->where('assignment_snapshot_hash', $storedHash)
            ->count();
        if ($boundHashes !== [$storedHash] || $boundCount !== $assignmentCount) {
            throw new LogicException('Existing assignments are not bound to the immutable input snapshot.');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{provider: RecipientMailboxProvider, mx: list<string>}
     */
    private function classification(array $row): array
    {
        $supplied = $row['recipient_provider'] ?? $row['provider'] ?? null;
        $provider = is_string($supplied)
            ? RecipientMailboxProvider::tryFrom($supplied)
            : ($supplied instanceof RecipientMailboxProvider ? $supplied : null);
        $snapshot = $row['provider_mx_snapshot'] ?? $row['mx'] ?? null;

        if ($provider instanceof RecipientMailboxProvider && is_array($snapshot)) {
            return [
                'provider' => $provider,
                'mx' => array_values(array_filter($snapshot, 'is_string')),
            ];
        }

        // Callers that already hold an audited provider snapshot (for example
        // a replay of a dry run) may provide the enum without re-querying DNS.
        if ($provider instanceof RecipientMailboxProvider && $supplied !== null) {
            return [
                'provider' => $provider,
                'mx' => [],
            ];
        }

        $inspected = $this->classifier->inspect((string) ($row['email_normalized'] ?? ''));

        return [
            'provider' => $inspected['provider'],
            'mx' => $inspected['mx'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateArray(mixed $candidate): array
    {
        if ($candidate instanceof Person) {
            return [
                'person_id' => $candidate->getKey(),
                'account_id' => $candidate->getAttribute('account_id'),
                'workspace_id' => $candidate->getAttribute('workspace_id'),
                'email_address' => $candidate->getAttribute('email_address'),
                'email_normalized' => $candidate->getAttribute('email_normalized'),
                'full_name' => $candidate->getAttribute('full_name'),
                'first_name' => $candidate->getAttribute('first_name'),
                'company' => $candidate->account?->getAttribute('name'),
            ];
        }

        if (is_object($candidate)) {
            $candidate = method_exists($candidate, 'toArray')
                ? $candidate->toArray()
                : get_object_vars($candidate);
        }

        return is_array($candidate) ? $candidate : [];
    }

    /** @param array<string, mixed> $row */
    private function assertCandidateWorkspace(array $row, int $workspaceId): void
    {
        if (array_key_exists('workspace_id', $row)
            && $row['workspace_id'] !== null
            && (int) $row['workspace_id'] !== $workspaceId) {
            throw new LogicException('Experiment candidate belongs to another workspace.');
        }

        if (is_numeric($row['person_id'] ?? null)) {
            $person = Person::query()->whereKey((int) $row['person_id'])->first();
            if (! $person || (int) $person->getAttribute('workspace_id') !== $workspaceId) {
                throw new LogicException('Experiment person belongs to another workspace.');
            }
        }

        if (is_numeric($row['account_id'] ?? null)) {
            $account = Account::query()->whereKey((int) $row['account_id'])->first();
            if (! $account || (int) $account->getAttribute('workspace_id') !== $workspaceId) {
                throw new LogicException('Experiment account belongs to another workspace.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $eligibility
     */
    private function eligibilityReason(array $row, int $workspaceId, array $eligibility): ?string
    {
        $email = (string) ($row['email_normalized'] ?? '');
        $address = (string) ($row['email_address'] ?? $email);

        if (isset($row['exclusion_reason'])) {
            return (string) $row['exclusion_reason'];
        }

        if (! EmailNormalizer::isValid($address)) {
            return 'invalid_email';
        }

        $local = strtolower((string) strstr($email, '@', true));
        $blockedLocalParts = self::ROLE_LOCAL_PARTS;
        if (is_array($configuredBlocked = $eligibility['blocked_local_parts'] ?? null)) {
            $blockedLocalParts = array_values(array_unique([
                ...$blockedLocalParts,
                ...array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), $configuredBlocked),
            ]));
        }
        $seedAllowlisted = $this->controlledSeedAllowlisted($email, $eligibility);
        if ((! $seedAllowlisted && $this->blockedLocalPart($local, $blockedLocalParts))
            || (! $seedAllowlisted && $this->controlledAddressFlag($row))) {
            return $this->blockedAddressReason($local, $row);
        }

        if (SuppressionEntry::query()
            ->where('workspace_id', $workspaceId)
            ->where('email_normalized', $email)
            ->exists()) {
            return 'suppressed';
        }

        if (CampaignRecipient::query()
            ->where('workspace_id', $workspaceId)
            ->where('email_normalized', $email)
            ->whereHas('campaign', fn ($campaign) => $campaign
                ->where('workspace_id', $workspaceId)
                ->where('status', CampaignStatus::Active->value))
            ->exists()) {
            return 'already_active_campaign';
        }

        if (CampaignRecipient::query()
            ->where('workspace_id', $workspaceId)
            ->where('email_normalized', $email)
            ->whereIn('status', [
                RecipientStatus::Replied->value,
                RecipientStatus::Interested->value,
                RecipientStatus::NotNow->value,
                RecipientStatus::WrongPerson->value,
                RecipientStatus::Bounced->value,
                RecipientStatus::Unsubscribed->value,
            ])
            ->exists()) {
            return 'previous_outcome';
        }

        $requiredFields = $eligibility['required_fields'] ?? [];
        if (is_array($requiredFields)) {
            foreach ($requiredFields as $field) {
                if (! is_string($field) || trim((string) ($row[$field] ?? '')) === '') {
                    return 'missing_required_field:'.$field;
                }
            }
        }

        // Compliance and legal fields are affirmative evidence. Missing or
        // non-boolean values are not an approval and must never silently pass.
        if (($row['compliance_eligible'] ?? null) !== true) {
            return 'compliance_not_approved';
        }

        if (($row['legal_eligible'] ?? null) !== true) {
            return 'legal_not_approved';
        }

        $countryReason = $this->countryApprovalReason($row, $eligibility);
        if ($countryReason !== null) {
            return $countryReason;
        }

        $relationshipReason = $this->relationshipApprovalReason($row, $eligibility);
        if ($relationshipReason !== null) {
            return $relationshipReason;
        }

        return null;
    }

    /**
     * A seed recipient is an exception only when its complete normalised
     * address appears in the experiment's explicit controlled allowlist.
     * Supporting both names keeps old configuration snapshots readable while
     * making the default deny behaviour unambiguous.
     *
     * @param  array<string, mixed>  $eligibility
     */
    private function controlledSeedAllowlisted(string $email, array $eligibility): bool
    {
        $allowlist = $eligibility['controlled_seed_allowlist']
            ?? $eligibility['seed_allowlist']
            ?? config('outboss.mail_experiments.seed_allowlist', []);
        if (! is_array($allowlist)) {
            return false;
        }

        foreach ($allowlist as $candidate) {
            if (is_string($candidate) && EmailNormalizer::normalize($candidate) === $email) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private function controlledAddressFlag(array $row): bool
    {
        foreach (['internal', 'is_internal', 'seed', 'is_seed', 'test', 'is_test', 'role', 'is_role', 'abuse', 'is_abuse', 'postmaster', 'is_postmaster', 'do_not_contact', 'is_do_not_contact'] as $key) {
            if (($row[$key] ?? null) === true) {
                return true;
            }
        }

        $source = strtolower(trim((string) ($row['source'] ?? '')));

        return in_array($source, ['internal', 'seed', 'test', 'role', 'abuse', 'postmaster', 'do_not_contact'], true);
    }

    /** @param array<string, mixed> $row */
    private function blockedAddressReason(string $local, array $row): string
    {
        $source = strtolower(trim((string) ($row['source'] ?? '')));
        if (in_array($source, ['internal', 'seed', 'test', 'role', 'abuse', 'postmaster', 'do_not_contact'], true)) {
            return $source === 'do_not_contact' ? 'do_not_contact_address' : $source.'_address';
        }

        return match ($local) {
            'abuse' => 'abuse_address',
            'postmaster' => 'postmaster_address',
            'do-not-contact', 'donotcontact' => 'do_not_contact_address',
            'seed' => 'seed_address',
            'test' => 'test_address',
            'internal' => 'internal_address',
            default => 'role_address',
        };
    }

    /** @param list<string> $blockedLocalParts */
    private function blockedLocalPart(string $local, array $blockedLocalParts): bool
    {
        foreach ($blockedLocalParts as $blocked) {
            $blocked = strtolower(trim($blocked));
            if ($blocked !== '' && ($local === $blocked
                || str_starts_with($local, $blocked.'+')
                || str_starts_with($local, $blocked.'.'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $eligibility
     */
    private function countryApprovalReason(array $row, array $eligibility): ?string
    {
        $rules = $eligibility['allowed_countries']
            ?? $eligibility['approved_countries']
            ?? $eligibility['countries']
            ?? null;
        if (is_array($rules) && $rules !== []) {
            $country = strtolower(trim((string) ($row['country_code'] ?? $row['country'] ?? '')));
            $allowed = array_values(array_filter(array_map(
                static fn (mixed $value): string => strtolower(trim((string) $value)),
                $rules,
            ), static fn (string $value): bool => $value !== ''));
            if ($country === '' || ! in_array($country, $allowed, true)) {
                return 'country_not_approved';
            }
        }

        if (($eligibility['require_country_approval'] ?? false) === true
            && ($row['country_approved'] ?? null) !== true) {
            return 'country_not_approved';
        }

        if (array_key_exists('country_approved', $row) && $row['country_approved'] !== true) {
            return 'country_not_approved';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $eligibility
     */
    private function relationshipApprovalReason(array $row, array $eligibility): ?string
    {
        $rules = $eligibility['allowed_relationships']
            ?? $eligibility['approved_relationships']
            ?? $eligibility['relationships']
            ?? null;
        if (is_array($rules) && $rules !== []) {
            $relationship = strtolower(trim((string) ($row['relationship'] ?? $row['relationship_status'] ?? '')));
            $allowed = array_values(array_filter(array_map(
                static fn (mixed $value): string => strtolower(trim((string) $value)),
                $rules,
            ), static fn (string $value): bool => $value !== ''));
            if ($relationship === '' || ! in_array($relationship, $allowed, true)) {
                return 'relationship_not_approved';
            }
        }

        if (($eligibility['require_relationship_approval'] ?? false) === true
            && ($row['relationship_approved'] ?? null) !== true) {
            return 'relationship_not_approved';
        }

        if (array_key_exists('relationship_approved', $row) && $row['relationship_approved'] !== true) {
            return 'relationship_not_approved';
        }

        return null;
    }

    /**
     * @param  EloquentCollection<int, MailExperimentCohort>  $cohorts
     * @param  list<AssignmentDecision>  $decisions
     * @return array<int, array<string, int>>
     */
    private function enrolCampaignRecipients(
        MailExperiment $experiment,
        EloquentCollection $cohorts,
        array $decisions,
    ): array {
        $recipientIds = [];

        foreach ($cohorts as $cohort) {
            $campaignId = $cohort->getAttribute('campaign_id');
            if ($campaignId === null) {
                continue;
            }

            $campaign = Campaign::query()
                ->where('workspace_id', $experiment->getAttribute('workspace_id'))
                ->whereKey($campaignId)
                ->first();
            if (! $campaign) {
                throw new LogicException('Cohort campaign does not belong to the experiment workspace.');
            }

            $candidates = [];
            foreach ($decisions as $decision) {
                if ($decision->cohortKey !== $cohort->key || $decision->isExcluded()) {
                    continue;
                }

                $candidates[] = new EnrollmentCandidate(
                    personId: $decision->personId,
                    accountId: $decision->accountId,
                    emailAddress: $decision->emailAddress ?: $decision->emailNormalized,
                    emailNormalized: $decision->emailNormalized,
                    fullName: $decision->fullName,
                    firstName: $decision->firstName,
                    company: $decision->company,
                );
            }

            if ($candidates !== []) {
                // The campaign enrolment service applies the same workspace,
                // suppression, and duplicate checks. Existing rows are safe
                // on an idempotent preparation retry, so we map them below.
                app(EnrollPeopleInCampaign::class)->handle($campaign, $candidates);
            }

            $recipientIds[(int) $campaignId] = CampaignRecipient::query()
                ->where('workspace_id', $experiment->getAttribute('workspace_id'))
                ->where('campaign_id', $campaignId)
                ->whereIn('email_normalized', collect($candidates)->pluck('emailNormalized')->all())
                ->pluck('id', 'email_normalized')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
        }

        return $recipientIds;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function stableApprovalEvidence(array $evidence): array
    {
        // `confirmed_at` is generated by the preparation boundary.  Keeping
        // it in the operator-visible configuration is useful audit metadata,
        // but it must not make a semantically identical retry look new.
        unset($evidence['confirmed_at']);

        /** @var array<string, mixed> $canonical */
        $canonical = $this->canonicalInputValue($evidence);

        return $canonical;
    }

    /**
     * Eligibility lists are set-like rules in the evaluator.  Sort their
     * members so an equivalent operator payload cannot invalidate a retry;
     * scalar values and unknown nested structures retain their meaning.
     *
     * @param  array<string, mixed>  $eligibility
     * @return array<string, mixed>
     */
    private function canonicalEligibility(array $eligibility): array
    {
        /** @var array<string, mixed> $canonical */
        $canonical = $this->canonicalInputValue($eligibility);

        foreach ([
            'blocked_local_parts',
            'required_fields',
            'allowed_countries',
            'approved_countries',
            'countries',
            'allowed_relationships',
            'approved_relationships',
            'relationships',
            'controlled_seed_allowlist',
            'seed_allowlist',
        ] as $key) {
            if (! is_array($canonical[$key] ?? null) || ! array_is_list($canonical[$key])) {
                continue;
            }

            $values = $canonical[$key];
            usort($values, static fn (mixed $left, mixed $right): int => (string) $left <=> (string) $right);
            $canonical[$key] = $values;
        }

        return $canonical;
    }

    private function canonicalInputJson(mixed $value): string
    {
        return (string) json_encode(
            $this->canonicalInputValue($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function canonicalInputValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalInputValue($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalInputValue($item);
        }

        return $value;
    }
}
