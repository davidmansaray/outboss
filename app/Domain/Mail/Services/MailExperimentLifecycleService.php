<?php

namespace App\Domain\Mail\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailExperimentDomainStrategy;
use App\Domain\Mail\Enums\MailExperimentStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Enums\ManagedMailDomainKind;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\MailExperiment;
use App\Models\MailExperimentCohort;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\SendAttempt;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use LogicException;

class MailExperimentLifecycleService
{
    public function __construct(
        private MailExperimentAssignmentService $assignments,
        private CampaignSnapshotService $snapshots,
        private LaunchCampaignService $campaigns,
        private FrozenMailExperimentInputGuard $frozenInputs,
    ) {}

    /**
     * Prepare assignments and, when supplied, clone the template campaign for
     * any cohort that does not already have one.
     *
     * @param  iterable<int, mixed>  $candidates
     * @param  array<string, mixed>  $approvalEvidence
     */
    public function prepare(
        MailExperiment $experiment,
        iterable $candidates,
        ?Campaign $template = null,
        array $approvalEvidence = [],
    ): MailExperiment {
        $materialisedCandidates = [];
        foreach ($candidates as $candidate) {
            $materialisedCandidates[] = $candidate;
        }

        $evidence = $approvalEvidence !== []
            ? $this->normaliseApprovalEvidence($approvalEvidence)
            : $this->candidateApprovalEvidence($materialisedCandidates);

        return DB::transaction(function () use ($experiment, $materialisedCandidates, $template, $evidence): MailExperiment {
            $locked = MailExperiment::query()->whereKey($experiment->id)->lockForUpdate()->firstOrFail();
            $locked->assertMutable();

            $hasAssignments = $locked->assignments()->exists();
            if ($hasAssignments) {
                $this->assertRetryApprovalEvidence($locked, $evidence);
                // The first preparation's evidence is immutable evidence. Do
                // not replace it with a newly timestamped equivalent payload.
                $evidence = null;
            }

            if (! $hasAssignments && $evidence !== null) {
                $configuration = $locked->getAttribute('configuration');
                $configuration = is_array($configuration) ? $configuration : [];
                $configuration['approval_evidence'] = $evidence;
                $locked->forceFill(['configuration' => $configuration])->save();
            }

            if ($template instanceof Campaign) {
                $cloner = app(CloneCampaignForExperiment::class);
                foreach ($locked->cohorts()->orderBy('key')->get() as $cohort) {
                    if ($cohort->getAttribute('campaign_id') === null) {
                        $cloner->handle($template, $cohort, null, $this->eligibility($locked));
                    }
                }
            }

            $this->assignments->handle($locked, $materialisedCandidates, $evidence ?? []);
            $locked->forceFill(['status' => MailExperimentStatus::Ready])->save();

            return $locked->fresh(['cohorts.campaign', 'cohorts.mailboxes', 'assignments']);
        });
    }

    public function freeze(MailExperiment $experiment): MailExperiment
    {
        return DB::transaction(function () use ($experiment): MailExperiment {
            $locked = MailExperiment::query()->whereKey($experiment->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($locked, [MailExperimentStatus::Draft, MailExperimentStatus::Ready]);
            $this->assertReadyForFreeze($locked);

            $eligibility = $this->eligibility($locked);
            $cohorts = $locked->cohorts()->orderBy('key')->get();
            $campaigns = $this->cohortCampaigns($locked, $cohorts);

            foreach ($cohorts as $index => $cohort) {
                $campaign = $campaigns[$index];
                $cohortSnapshot = $this->frozenCohortSnapshot($cohort, $campaign, $eligibility, $locked);
                $cohort->forceFill([
                    'mailbox_topology_snapshot' => $cohortSnapshot['mailbox_topology'],
                    'snapshot_hash' => $this->snapshots->hash($cohortSnapshot),
                ])->save();
            }

            if (count($campaigns) > 1) {
                $reference = $campaigns[0];
                foreach (array_slice($campaigns, 1) as $campaign) {
                    $comparison = $this->snapshots->compare($reference, $campaign, $eligibility, $locked);
                    if (! $comparison['equivalent']) {
                        throw new LogicException(
                            'Cohort campaign inputs differ in non-approved fields: '.implode(', ', $comparison['differences']),
                        );
                    }
                }
            }

            $locked->forceFill([
                'status' => MailExperimentStatus::Ready,
                'frozen_at' => now(),
            ])->save();

            return $locked->fresh(['cohorts.campaign', 'cohorts.mailboxes', 'assignments']);
        });
    }

    public function launch(MailExperiment $experiment): MailExperiment
    {
        // Persist an input-change abort before surfacing the launch failure.
        // Throwing from inside the transaction would roll the audit mutation
        // back along with the attempted status transition.
        $abortReason = null;
        $result = DB::transaction(function () use ($experiment, &$abortReason): MailExperiment {
            $locked = MailExperiment::query()->whereKey($experiment->id)->lockForUpdate()->firstOrFail();
            $current = $this->status($locked);
            if (! in_array($current, [MailExperimentStatus::Ready], true) || ! $current->canTransitionTo(MailExperimentStatus::Running)) {
                throw new LogicException("Cannot transition mail experiment from {$current->value} to ".MailExperimentStatus::Running->value.'.');
            }

            // Launch is a second, execution-time gate. Domain/account state,
            // campaign ownership, and the exact six-mailbox topology may have
            // changed after review, even though the experiment row is frozen.
            $abortReason = $this->assertFrozenInputs($locked);
            if ($abortReason !== null) {
                $this->abortForInputChange($locked, $abortReason);

                return $locked->fresh();
            }

            $cohortCampaigns = $this->cohortCampaigns($locked);
            foreach ($cohortCampaigns as $campaign) {
                $this->frozenInputs->runTrustedLifecycleMutation(
                    fn (): Campaign => $this->campaigns->handleTrustedLifecycle($campaign),
                );
            }

            $locked->forceFill([
                'status' => MailExperimentStatus::Running,
                'started_at' => $locked->getAttribute('started_at') ?? now(),
            ])->save();

            return $locked->fresh();
        });

        if ($abortReason !== null) {
            throw new LogicException('A frozen cohort campaign changed after freeze; the experiment was aborted.');
        }

        return $result;
    }

    public function pause(MailExperiment $experiment): MailExperiment
    {
        return $this->transition($experiment, MailExperimentStatus::Paused, [MailExperimentStatus::Running]);
    }

    public function resume(MailExperiment $experiment): MailExperiment
    {
        return $this->transition($experiment, MailExperimentStatus::Running, [MailExperimentStatus::Paused], function (MailExperiment $locked): void {
            $reason = $this->assertFrozenInputs($locked);
            if ($reason !== null) {
                throw new LogicException('A frozen cohort campaign changed after freeze; the experiment cannot resume.');
            }
        });
    }

    public function complete(MailExperiment $experiment): MailExperiment
    {
        return $this->transition($experiment, MailExperimentStatus::Completed, [MailExperimentStatus::Running, MailExperimentStatus::Paused], function (MailExperiment $locked): void {
            $locked->forceFill(['completed_at' => now()]);
        });
    }

    public function abort(MailExperiment $experiment, ?string $reason = null): MailExperiment
    {
        return DB::transaction(function () use ($experiment, $reason): MailExperiment {
            $locked = MailExperiment::query()->whereKey($experiment->id)->lockForUpdate()->firstOrFail();
            $status = $this->status($locked);

            if ($status->isTerminal()) {
                throw new LogicException('A completed or aborted experiment cannot be changed.');
            }

            // There is no campaign-level "abort" state. Pausing all active
            // cohort campaigns is the safe equivalent and defers queued work
            // before the experiment is made terminal.
            $this->synchroniseCampaigns($locked, 'abort');

            $incidents = $locked->getAttribute('incidents');
            $incidents = is_array($incidents) ? $incidents : [];
            if ($reason !== null && $reason !== '') {
                $incidents[] = ['type' => 'experiment_aborted', 'reason' => $reason, 'at' => now()->toIso8601String()];
            }

            $locked->forceFill([
                'status' => MailExperimentStatus::Aborted,
                'aborted_at' => now(),
                'incidents' => $incidents,
                'incident_count' => max((int) $locked->incident_count, count($incidents)),
            ])->save();

            return $locked->fresh();
        });
    }

    /**
     * Attach a mailbox set to a cohort while maintaining the denormalised
     * pivot experiment ID and the no-cross-cohort invariant.
     *
     * @param  array<int, int|Mailbox>  $mailboxes
     */
    public function attachMailboxes(MailExperimentCohort $cohort, array $mailboxes): MailExperimentCohort
    {
        return DB::transaction(function () use ($cohort, $mailboxes): MailExperimentCohort {
            $cohort = MailExperimentCohort::query()->whereKey($cohort->id)->lockForUpdate()->firstOrFail();
            $experiment = $cohort->experiment()->lockForUpdate()->firstOrFail();
            $experiment->assertMutable();
            $workspaceId = (int) $experiment->getAttribute('workspace_id');
            $pivot = [];
            $seenMailboxIds = [];
            $strategy = $this->strategy($cohort);

            foreach (array_values($mailboxes) as $position => $mailbox) {
                $mailboxId = $mailbox instanceof Mailbox ? $mailbox->id : (int) $mailbox;
                if ($mailboxId < 1 || isset($seenMailboxIds[$mailboxId])) {
                    throw new LogicException('A cohort mailbox list must contain distinct mailbox IDs.');
                }
                $seenMailboxIds[$mailboxId] = true;

                $candidate = Mailbox::query()
                    ->whereKey($mailboxId)
                    ->where('workspace_id', $workspaceId)
                    ->with(['connection', 'managedMailboxAccount.managedMailDomain'])
                    ->first();
                if (! $candidate instanceof Mailbox) {
                    throw new LogicException('A cohort mailbox must belong to the experiment workspace.');
                }

                $this->assertAttachableMailbox($candidate, $strategy, $workspaceId);

                $already = DB::table('mail_experiment_cohort_mailbox')
                    ->join('mail_experiment_cohorts', 'mail_experiment_cohorts.id', '=', 'mail_experiment_cohort_mailbox.mail_experiment_cohort_id')
                    ->where('mail_experiment_cohort_mailbox.mailbox_id', $mailboxId)
                    ->where('mail_experiment_cohorts.mail_experiment_id', $experiment->id)
                    ->where('mail_experiment_cohort_mailbox.mail_experiment_cohort_id', '!=', $cohort->id)
                    ->exists();
                if ($already) {
                    throw new LogicException('A mailbox cannot belong to two cohorts in one experiment.');
                }

                $pivot[$mailboxId] = [
                    'mail_experiment_id' => $experiment->id,
                    'position' => $position + 1,
                ];
            }

            $cohort->mailboxes()->sync($pivot);

            return $cohort->fresh('mailboxes');
        });
    }

    private function assertAttachableMailbox(
        Mailbox $mailbox,
        MailExperimentDomainStrategy $strategy,
        int $workspaceId,
    ): void {
        $account = $mailbox->managedMailboxAccount;
        $domain = $account?->managedMailDomain;
        $connection = $mailbox->connection;

        if (! $account instanceof ManagedMailboxAccount
            || $account->getAttribute('status') !== ManagedMailboxAccountStatus::Active
            || (int) $account->workspaceId() !== $workspaceId
            || ! $domain instanceof ManagedMailDomain
            || (int) $domain->getAttribute('workspace_id') !== $workspaceId
            || $domain->getAttribute('status') !== ManagedMailDomainStatus::Active
            || $domain->getAttribute('verified_at') === null
            || $domain->getAttribute('provider') !== MailProviderName::Stalwart
            || $domain->getAttribute('domain_kind') !== $this->domainKindFor($strategy)
            || $mailbox->getAttribute('status') !== ConnectionStatus::Connected
            || $connection === null
            || $connection->getAttribute('provider') !== MailProviderName::Stalwart
            || $connection->getAttribute('connection_kind') !== ConnectionKind::Managed
            || $connection->getAttribute('status') !== ConnectionStatus::Connected) {
            throw new LogicException('Only active managed Stalwart mailboxes with a verified domain matching the cohort strategy may be attached.');
        }
    }

    /** @param list<MailExperimentStatus> $allowed */
    private function transition(
        MailExperiment $experiment,
        MailExperimentStatus $target,
        array $allowed,
        ?callable $beforeSave = null,
    ): MailExperiment {
        return DB::transaction(function () use ($experiment, $target, $allowed, $beforeSave): MailExperiment {
            $locked = MailExperiment::query()->whereKey($experiment->id)->lockForUpdate()->firstOrFail();
            $current = $this->status($locked);
            if (! in_array($current, $allowed, true) || ! $current->canTransitionTo($target)) {
                throw new LogicException("Cannot transition mail experiment from {$current->value} to {$target->value}.");
            }

            if ($target === MailExperimentStatus::Completed) {
                $this->assertNoUnsettledAttempts($locked);
            }

            if ($beforeSave !== null) {
                $beforeSave($locked);
            }

            // Validate transition-specific invariants before synchronising
            // cohort campaigns. In particular, resume must not publish a
            // changed draft before the frozen hashes have been checked.
            $this->synchroniseCampaigns($locked, match ($target) {
                MailExperimentStatus::Paused => 'pause',
                MailExperimentStatus::Running => 'resume',
                MailExperimentStatus::Completed => 'complete',
                default => null,
            });

            $locked->forceFill(['status' => $target])->save();

            return $locked->fresh();
        });
    }

    /** @param list<MailExperimentStatus> $allowed */
    private function assertStatus(MailExperiment $experiment, array $allowed): void
    {
        if (! in_array($this->status($experiment), $allowed, true)) {
            throw new LogicException('Mail experiment is not in a mutable preparation state.');
        }
    }

    private function status(MailExperiment $experiment): MailExperimentStatus
    {
        $status = $experiment->getAttribute('status');

        return $status instanceof MailExperimentStatus
            ? $status
            : (MailExperimentStatus::tryFrom((string) $status) ?? MailExperimentStatus::Draft);
    }

    private function assertReadyForFreeze(MailExperiment $experiment): void
    {
        $cohorts = $experiment->cohorts()->orderBy('key')->get();
        if ($cohorts->count() !== 2) {
            throw new LogicException('Exactly two strategy cohorts are required before freezing an experiment.');
        }

        $strategies = $cohorts->map(fn (MailExperimentCohort $cohort): string => $this->strategy($cohort)->value)->all();
        sort($strategies, SORT_STRING);
        if ($strategies !== [
            MailExperimentDomainStrategy::SeparateRootDomains->value,
            MailExperimentDomainStrategy::Subdomain->value,
        ]) {
            throw new LogicException('The experiment must contain one subdomain cohort and one separate-root-domains cohort.');
        }

        if (! $experiment->assignments()->exists()) {
            throw new LogicException('Prepare assignments before freezing an experiment.');
        }

        $approvalEvidence = $this->approvalEvidence($experiment);
        if (($approvalEvidence['compliance_review_confirmed'] ?? null) !== true
            || ($approvalEvidence['legal_review_confirmed'] ?? null) !== true) {
            throw new LogicException('Affirmative compliance and legal review evidence is required before freezing an experiment.');
        }

        $this->assignments->assertPersistedInputsCurrent($experiment);
        $this->assertAssignmentTargets($experiment, $cohorts);
        $this->assertManagedMailboxes($experiment, $cohorts);
        $this->cohortCampaigns($experiment, $cohorts);
    }

    /**
     * A configured target is an exact approved sample size for every cohort,
     * not merely a display hint. Without an explicit target, the prepared
     * audience must still produce two non-empty arms whose sizes differ by at
     * most one. Preparation may retain a smaller deterministic sample when the
     * eligible audience is too small, but that incomplete wave must not be
     * frozen or launched as if it were approved.
     *
     * @param  EloquentCollection<int, MailExperimentCohort>  $cohorts
     */
    private function assertAssignmentTargets(
        MailExperiment $experiment,
        EloquentCollection $cohorts,
    ): void {
        $rawTarget = $experiment->getAttribute('target_assignments_per_cohort');
        if ($rawTarget === null || $rawTarget === '') {
            $counts = $cohorts->map(fn (MailExperimentCohort $cohort): int => $experiment->assignments()
                ->where('mail_experiment_cohort_id', $cohort->getKey())
                ->whereNull('exclusion_reason')
                ->count());

            if ($counts->min() < 1 || ($counts->max() - $counts->min()) > 1) {
                throw new LogicException('The prepared experiment cohorts must be non-empty and balanced before freezing.');
            }

            return;
        }

        if (! is_numeric($rawTarget) || (int) $rawTarget < 1) {
            throw new LogicException('The target assignments per cohort must be at least one.');
        }

        $target = (int) $rawTarget;
        foreach ($cohorts as $cohort) {
            $count = $experiment->assignments()
                ->where('mail_experiment_cohort_id', $cohort->getKey())
                ->whereNull('exclusion_reason')
                ->count();

            if ($count !== $target) {
                throw new LogicException(sprintf(
                    'Cohort %s has %d approved assignments; exactly %d are required before freezing.',
                    (string) $cohort->getAttribute('key'),
                    $count,
                    $target,
                ));
            }
        }
    }

    private function assertFrozenInputs(MailExperiment $experiment): ?string
    {
        if (! $experiment->isFrozen()) {
            throw new LogicException('Freeze the experiment before launching it.');
        }

        $cohorts = $experiment->cohorts()->with(['campaign', 'mailboxes.connection', 'mailboxes.managedMailboxAccount.managedMailDomain'])->orderBy('key')->get();
        try {
            $this->assertManagedMailboxes($experiment, $cohorts);
            $this->assignments->assertPersistedInputsCurrent($experiment);
            $campaigns = $this->cohortCampaigns($experiment, $cohorts);
        } catch (LogicException) {
            return 'cohort_mailbox_topology_changed';
        }

        $eligibility = $this->eligibility($experiment);
        foreach ($cohorts as $index => $cohort) {
            $campaign = $cohort->campaign;
            $snapshotHash = $cohort->getAttribute('snapshot_hash');
            $storedTopology = $cohort->getAttribute('mailbox_topology_snapshot');
            if (! $campaign instanceof Campaign || $snapshotHash === null || ! is_array($storedTopology)) {
                return 'cohort_campaign_missing_snapshot';
            }

            $currentSnapshot = $this->frozenCohortSnapshot($cohort, $campaign, $eligibility, $experiment);
            if ($this->canonicalJson($storedTopology) !== $this->canonicalJson($currentSnapshot['mailbox_topology'])) {
                return 'cohort_mailbox_topology_changed';
            }

            $currentHash = $this->snapshots->hash($currentSnapshot);
            if (! hash_equals((string) $snapshotHash, $currentHash)) {
                $legacyHash = $this->snapshots->legacyHashIfVariationsDisabled($currentSnapshot);
                if ($legacyHash === null || ! hash_equals((string) $snapshotHash, $legacyHash)) {
                    return 'cohort_campaign_snapshot_changed';
                }
            }

            if (! isset($campaigns[$index]) || (int) $campaigns[$index]->getKey() !== (int) $campaign->getKey()) {
                return 'cohort_campaign_snapshot_changed';
            }
        }

        return null;
    }

    /**
     * Build the immutable evidence hash for one cohort.  Sender signatures
     * remain in the campaign snapshot, while the experiment-owned mailbox
     * relation is captured separately so replacing a valid mailbox cannot
     * evade launch checks merely because the campaign pivot was unchanged.
     *
     * @param  array<string, mixed>  $eligibility
     * @return array<string, mixed>
     */
    private function frozenCohortSnapshot(
        MailExperimentCohort $cohort,
        Campaign $campaign,
        array $eligibility,
        MailExperiment $experiment,
    ): array {
        return [
            'campaign' => $this->snapshots->snapshot($campaign, $eligibility, $experiment),
            'mailbox_topology' => $this->mailboxTopologySnapshot($cohort),
        ];
    }

    /**
     * Capture the exact ordered cohort mailbox topology, including the
     * managed identity joins used by the launch gate.  Health/status values
     * are included deliberately: a frozen sender that is no longer the same
     * connected managed identity must not launch under old evidence.
     *
     * @return array<string, mixed>
     */
    private function mailboxTopologySnapshot(MailExperimentCohort $cohort): array
    {
        $mailboxes = $cohort->mailboxes()
            ->with(['connection', 'managedMailboxAccount.managedMailDomain'])
            ->orderByPivot('position')
            ->get();

        return [
            'cohort_id' => (int) $cohort->getKey(),
            'cohort_key' => (string) $cohort->getAttribute('key'),
            'domain_strategy' => $this->enumValue($cohort->getAttribute('domain_strategy')),
            'mailboxes' => $mailboxes->map(function (Mailbox $mailbox): array {
                $pivot = $mailbox->getAttribute('pivot');
                $connection = $mailbox->connection;
                $account = $mailbox->managedMailboxAccount;
                $domain = $account?->managedMailDomain;

                return [
                    'position' => (int) ($pivot?->getAttribute('position') ?? 0),
                    'mailbox_id' => (int) $mailbox->getKey(),
                    'email' => strtolower(trim((string) $mailbox->getAttribute('email_address'))),
                    'workspace_id' => (int) $mailbox->getAttribute('workspace_id'),
                    'status' => $this->enumValue($mailbox->getAttribute('status')),
                    'provider_connection_id' => $connection?->getKey() === null ? null : (int) $connection->getKey(),
                    'connection_provider' => $this->enumValue($connection?->getAttribute('provider')),
                    'connection_kind' => $this->enumValue($connection?->getAttribute('connection_kind')),
                    'connection_status' => $this->enumValue($connection?->getAttribute('status')),
                    'managed_mailbox_account_id' => $account?->getKey() === null ? null : (int) $account->getKey(),
                    'managed_mailbox_account_status' => $this->enumValue($account?->getAttribute('status')),
                    'managed_mail_domain_id' => $domain?->getKey() === null ? null : (int) $domain->getKey(),
                    'managed_domain' => $domain?->getAttribute('domain') === null
                        ? null
                        : strtolower(trim((string) $domain->getAttribute('domain'))),
                    'managed_domain_kind' => $this->enumValue($domain?->getAttribute('domain_kind')),
                    'managed_domain_provider' => $this->enumValue($domain?->getAttribute('provider')),
                    'managed_domain_status' => $this->enumValue($domain?->getAttribute('status')),
                    'managed_domain_verified_at' => $this->dateValue($domain?->getAttribute('verified_at')),
                ];
            })->values()->all(),
        ];
    }

    private function canonicalJson(mixed $value): string
    {
        $canonical = function (mixed $item) use (&$canonical): mixed {
            if ($item instanceof \BackedEnum) {
                return $item->value;
            }

            if ($item instanceof \DateTimeInterface) {
                return $item->format(DATE_ATOM);
            }

            if (! is_array($item)) {
                return $item;
            }

            if (array_is_list($item)) {
                return array_map($canonical, $item);
            }

            ksort($item, SORT_STRING);
            foreach ($item as $key => $child) {
                $item[$key] = $canonical($child);
            }

            return $item;
        };

        return (string) json_encode($canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value === null ? null : (string) $value;
    }

    /**
     * Resolve the complete, ordered campaign set for an experiment. A cohort
     * campaign must exist, belong to the same workspace, and may not silently
     * claim a different cohort through the reverse foreign key.
     *
     * @param  EloquentCollection<int, MailExperimentCohort>|null  $cohorts
     * @return list<Campaign>
     */
    private function cohortCampaigns(MailExperiment $experiment, ?EloquentCollection $cohorts = null): array
    {
        $cohorts ??= $experiment->cohorts()->orderBy('key')->get();
        $workspaceId = (int) $experiment->getAttribute('workspace_id');
        $campaigns = [];
        $seen = [];

        foreach ($cohorts as $cohort) {
            $campaignId = $cohort->getAttribute('campaign_id');
            $campaign = is_numeric($campaignId)
                ? Campaign::query()
                    ->whereKey((int) $campaignId)
                    ->where('workspace_id', $workspaceId)
                    ->first()
                : null;

            if (! $campaign instanceof Campaign) {
                throw new LogicException('Every experiment cohort must have a matching campaign in the experiment workspace.');
            }

            if (isset($seen[$campaign->id])) {
                throw new LogicException('Each experiment cohort must have a distinct campaign.');
            }

            $linkedCohortId = $campaign->getAttribute('mail_experiment_cohort_id');
            if ($linkedCohortId !== null && (int) $linkedCohortId !== (int) $cohort->id) {
                throw new LogicException('A cohort campaign is linked to a different experiment cohort.');
            }

            $seen[$campaign->id] = true;
            $campaigns[] = $campaign;
        }

        return $campaigns;
    }

    /**
     * Validate the six-mailbox pilot topology. The campaign flow still uses
     * ordinary Mailbox records, but an experiment may only attach an ACTIVE
     * managed Stalwart account whose domain kind matches its strategy.
     *
     * @param  EloquentCollection<int, MailExperimentCohort>  $cohorts
     */
    private function assertManagedMailboxes(MailExperiment $experiment, EloquentCollection $cohorts): void
    {
        $workspaceId = (int) $experiment->getAttribute('workspace_id');
        $allMailboxIds = [];

        foreach ($cohorts as $cohort) {
            $mailboxes = $cohort->mailboxes()
                ->with(['connection', 'managedMailboxAccount.managedMailDomain'])
                ->get();

            if ($mailboxes->count() !== 3) {
                throw new LogicException('Each experiment cohort must have exactly three active managed Stalwart mailboxes.');
            }

            $strategy = $this->strategy($cohort);
            $domainIds = [];
            foreach ($mailboxes as $mailbox) {
                if (isset($allMailboxIds[$mailbox->id])) {
                    throw new LogicException('A managed mailbox cannot be attached to both experiment cohorts.');
                }
                $allMailboxIds[$mailbox->id] = true;

                $account = $mailbox->managedMailboxAccount;
                $domain = $account?->managedMailDomain;
                $connection = $mailbox->connection;

                if (! $account instanceof ManagedMailboxAccount
                    || $account->getAttribute('status') !== ManagedMailboxAccountStatus::Active
                    || (int) $mailbox->getAttribute('workspace_id') !== $workspaceId
                    || (int) $account->workspaceId() !== $workspaceId
                    || ! $domain instanceof ManagedMailDomain
                    || (int) $domain->getAttribute('workspace_id') !== $workspaceId
                    || $domain->getAttribute('status') !== ManagedMailDomainStatus::Active
                    || $domain->getAttribute('verified_at') === null
                    || $domain->getAttribute('provider') !== MailProviderName::Stalwart
                    || $domain->getAttribute('domain_kind') !== $this->domainKindFor($strategy)
                    || $mailbox->getAttribute('status') !== ConnectionStatus::Connected
                    || $connection === null
                    || $connection->getAttribute('provider') !== MailProviderName::Stalwart
                    || $connection->getAttribute('connection_kind') !== ConnectionKind::Managed
                    || $connection->getAttribute('status') !== ConnectionStatus::Connected) {
                    throw new LogicException('Every cohort mailbox must be an active managed Stalwart mailbox with a verified domain matching its strategy.');
                }

                $domainIds[] = (int) $domain->getKey();
            }

            $uniqueDomainCount = count(array_unique($domainIds));
            if (($strategy === MailExperimentDomainStrategy::Subdomain && $uniqueDomainCount !== 1)
                || ($strategy === MailExperimentDomainStrategy::SeparateRootDomains && $uniqueDomainCount !== 3)) {
                throw new LogicException($strategy === MailExperimentDomainStrategy::Subdomain
                    ? 'The subdomain cohort must use one verified managed domain for all three mailboxes.'
                    : 'The separate-root-domains cohort must use three distinct verified managed domains.');
            }
        }

        if (count($allMailboxIds) !== 6) {
            throw new LogicException('The experiment requires six distinct active managed Stalwart mailboxes.');
        }
    }

    private function strategy(MailExperimentCohort $cohort): MailExperimentDomainStrategy
    {
        $value = $cohort->getAttribute('domain_strategy');

        return $value instanceof MailExperimentDomainStrategy
            ? $value
            : (MailExperimentDomainStrategy::tryFrom((string) $value)
                ?? throw new LogicException('Every experiment cohort must use a supported domain strategy.'));
    }

    private function domainKindFor(MailExperimentDomainStrategy $strategy): ManagedMailDomainKind
    {
        return $strategy === MailExperimentDomainStrategy::Subdomain
            ? ManagedMailDomainKind::Subdomain
            : ManagedMailDomainKind::Root;
    }

    /**
     * Synchronise campaign state with an experiment lifecycle transition.
     * Active work is always deferred before a campaign is paused/completed.
     */
    private function synchroniseCampaigns(MailExperiment $experiment, ?string $action): void
    {
        if ($action === null) {
            return;
        }

        $status = $this->status($experiment);
        if ($action !== 'abort'
            && ! in_array($status, [MailExperimentStatus::Running, MailExperimentStatus::Paused], true)) {
            return;
        }

        $campaigns = $action === 'abort'
            ? $this->campaignsForAbort($experiment)
            : $this->cohortCampaigns($experiment);

        foreach ($campaigns as $campaign) {
            $campaignStatus = $this->campaignStatus($campaign);

            if ($action === 'pause' || $action === 'abort') {
                if ($campaignStatus === CampaignStatus::Active) {
                    $this->campaigns->pause($campaign);
                }

                continue;
            }

            if ($action === 'resume') {
                if ($campaignStatus === CampaignStatus::Paused) {
                    $this->frozenInputs->runTrustedLifecycleMutation(
                        fn (): Campaign => $this->campaigns->resumeTrustedLifecycle($campaign),
                    );
                }

                continue;
            }

            if ($action === 'complete') {
                if (in_array($campaignStatus, [CampaignStatus::Active, CampaignStatus::Paused], true)) {
                    $this->frozenInputs->runTrustedLifecycleMutation(
                        fn (): Campaign => $this->campaigns->complete($campaign),
                    );
                }
            }
        }
    }

    /** @return list<Campaign> */
    private function campaignsForAbort(MailExperiment $experiment): array
    {
        $workspaceId = (int) $experiment->getAttribute('workspace_id');
        $campaigns = [];

        foreach ($experiment->cohorts()->with('campaign')->get() as $cohort) {
            $campaign = $cohort->campaign;
            if ($campaign instanceof Campaign && (int) $campaign->getAttribute('workspace_id') === $workspaceId) {
                $campaigns[] = $campaign;
            }
        }

        return $campaigns;
    }

    private function campaignStatus(Campaign $campaign): CampaignStatus
    {
        $status = $campaign->getAttribute('status');

        return $status instanceof CampaignStatus
            ? $status
            : (CampaignStatus::tryFrom((string) $status) ?? CampaignStatus::Draft);
    }

    /**
     * A completed experiment is an accounting boundary. The operator may
     * exclude deferred/pre-provider failures and already settled provider
     * outcomes, but no work that can still submit or whose provider outcome
     * is uncertain may remain. The row lock makes the check and transition
     * one transaction against concurrent schedulers/workers.
     */
    private function assertNoUnsettledAttempts(MailExperiment $experiment): void
    {
        $campaignIds = $experiment->cohorts()
            ->whereNotNull('campaign_id')
            ->pluck('campaign_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->all();

        if ($campaignIds === []) {
            return;
        }

        $recipientIds = CampaignRecipient::query()
            ->whereIn('campaign_id', $campaignIds)
            ->select('id');
        $unsettledStatuses = array_map(
            static fn (SendAttemptStatus $status): string => $status->value,
            array_filter(
                SendAttemptStatus::cases(),
                static fn (SendAttemptStatus $status): bool => $status->blocksExperimentCompletion(),
            ),
        );

        $statuses = SendAttempt::query()
            ->whereIn('campaign_recipient_id', $recipientIds)
            ->whereIn('status', $unsettledStatuses)
            ->lockForUpdate()
            ->pluck('status')
            ->map(static fn (mixed $status): string => $status instanceof SendAttemptStatus ? $status->value : (string) $status)
            ->unique()
            ->values()
            ->all();

        if ($statuses !== []) {
            throw new LogicException('The experiment has unsettled send attempts: '.implode(', ', $statuses).'. Resolve or explicitly exclude them before completing.');
        }
    }

    private function abortForInputChange(MailExperiment $experiment, string $reason): void
    {
        $incidents = $experiment->getAttribute('incidents');
        $incidents = is_array($incidents) ? $incidents : [];
        $incidents[] = ['type' => 'input_changed', 'reason' => $reason, 'at' => now()->toIso8601String()];
        $experiment->forceFill([
            'status' => MailExperimentStatus::Aborted,
            'aborted_at' => now(),
            'incidents' => $incidents,
            'incident_count' => max((int) $experiment->getAttribute('incident_count'), count($incidents)),
        ])->save();
    }

    /** @return array<string, mixed> */
    private function eligibility(MailExperiment $experiment): array
    {
        $configuration = $experiment->getAttribute('configuration');

        return is_array($configuration) && is_array($configuration['eligibility'] ?? null)
            ? $configuration['eligibility']
            : [];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function normaliseApprovalEvidence(array $evidence): array
    {
        if (($evidence['compliance_review_confirmed'] ?? null) !== true
            || ($evidence['legal_review_confirmed'] ?? null) !== true) {
            throw new LogicException('Both compliance and legal review must be affirmatively confirmed before preparation.');
        }

        $normalised = [
            'compliance_review_confirmed' => true,
            'legal_review_confirmed' => true,
            'confirmed_at' => now()->toIso8601String(),
            'source' => in_array($evidence['source'] ?? null, ['http', 'cli', 'candidate_records'], true)
                ? $evidence['source']
                : 'service',
        ];

        if (is_numeric($evidence['confirmed_by_user_id'] ?? null)) {
            $normalised['confirmed_by_user_id'] = (int) $evidence['confirmed_by_user_id'];
        }

        foreach (['confirmed_by', 'reference'] as $key) {
            if (! is_string($evidence[$key] ?? null) || trim($evidence[$key]) === '') {
                continue;
            }

            $normalised[$key] = mb_substr(str_replace(["\r", "\n"], ' ', trim($evidence[$key])), 0, 255);
        }

        return $normalised;
    }

    /**
     * Preserve the existing trusted array-service API: explicit affirmative
     * evidence on every candidate is audit evidence, not a global default.
     *
     * @param  list<mixed>  $candidates
     * @return array<string, mixed>|null
     */
    private function candidateApprovalEvidence(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (is_object($candidate)) {
                $candidate = method_exists($candidate, 'toArray')
                    ? $candidate->toArray()
                    : get_object_vars($candidate);
            }

            if (! is_array($candidate)
                || ($candidate['compliance_eligible'] ?? null) !== true
                || ($candidate['legal_eligible'] ?? null) !== true) {
                return null;
            }
        }

        return $this->normaliseApprovalEvidence([
            'compliance_review_confirmed' => true,
            'legal_review_confirmed' => true,
            'source' => 'candidate_records',
            'reference' => 'Affirmative eligibility fields on every prepared candidate record.',
        ]);
    }

    /**
     * A retry must prove the same approval decision that was bound to the
     * original assignment snapshot.  In particular, an omitted confirmation
     * cannot silently fall back to the old configuration evidence.
     *
     * @param  array<string, mixed>|null  $evidence
     */
    private function assertRetryApprovalEvidence(MailExperiment $experiment, ?array $evidence): void
    {
        $snapshot = $experiment->getAttribute('assignment_snapshot');
        $bound = is_array($snapshot) && is_array($snapshot['approval_evidence'] ?? null)
            ? $snapshot['approval_evidence']
            : [];

        if ($evidence === null) {
            if ($bound !== []) {
                throw new LogicException('The original compliance and legal approval evidence is required for a preparation retry.');
            }

            return;
        }

        if ($this->canonicalEvidence($bound) !== $this->canonicalEvidence($evidence)) {
            throw new LogicException('Preparation approval evidence differs from the existing assignment snapshot.');
        }
    }

    /** @param array<string, mixed> $evidence */
    private function canonicalEvidence(array $evidence): string
    {
        unset($evidence['confirmed_at']);
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (array_is_list($value)) {
                return array_map($sort, $value);
            }

            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = $sort($item);
            }

            return $value;
        };

        return (string) json_encode($sort($evidence), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function approvalEvidence(MailExperiment $experiment): array
    {
        $configuration = $experiment->getAttribute('configuration');
        $evidence = is_array($configuration) ? ($configuration['approval_evidence'] ?? null) : null;

        return is_array($evidence) ? $evidence : [];
    }
}
