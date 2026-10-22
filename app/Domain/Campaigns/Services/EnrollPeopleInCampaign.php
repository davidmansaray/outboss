<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\DTOs\EnrollmentCandidate;
use App\Domain\Campaigns\DTOs\EnrollmentResult;
use App\Domain\Campaigns\DTOs\EnrollmentSkip;
use App\Domain\Campaigns\DTOs\GovernedCampaignAction;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\MailboxAssignment;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SendMode;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use App\Models\SuppressionEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class EnrollPeopleInCampaign
{
    public const REASON_SUPPRESSED = 'Suppressed';

    public const REASON_ALREADY_ENROLLED = 'Already in campaign';

    public const REASON_INVALID = 'Invalid email';

    public function __construct(
        private FrozenMailExperimentInputGuard $frozenInputs,
        private GovernedCampaignInputGuard $governedInputs,
        private MailboxSendBudget $sendBudget,
    ) {}

    /**
     * Create recipient rows for the given candidates.
     *
     * Must run inside the caller's transaction with the campaign already
     * locked (`lockForUpdate`). Do not open a nested transaction here.
     *
     * @param  list<EnrollmentCandidate>  $candidates
     */
    public function handle(Campaign $campaign, array $candidates): EnrollmentResult
    {
        $this->governedInputs->assertCampaignMutable($campaign);

        // A frozen experiment owns its audience.  Keep this check at the
        // shared enrolment primitive so registry enrolments, CSV commits, and
        // internal preparation callers cannot add a post-freeze recipient.
        $this->frozenInputs->assertCampaignAudienceMutable($campaign);

        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        $mailboxes = $campaign->mailboxes()
            ->where('mailboxes.workspace_id', $workspaceId)
            ->orderByPivot('position')
            ->get();

        $allowUnassigned = $this->isCampaignStatus($campaign->getAttribute('status'), CampaignStatus::Draft)
            && $campaign->launched_at === null;
        if ($mailboxes->isEmpty() && ! $allowUnassigned) {
            throw ValidationException::withMessages([
                'person_ids' => 'Assign a mailbox from this workspace before importing recipients.',
            ]);
        }

        $campaign->loadMissing(['publishedVersion.activeSteps', 'draftVersion.activeSteps']);

        $existing = $campaign->recipients()
            ->where('status', '!=', RecipientStatus::Removed)
            ->count();
        $enrolledEmails = $this->existingEmails($campaign);
        $enrolledPersonIds = $this->existingPersonIds($campaign);
        $suppressed = $this->suppressedEmails($workspaceId, $candidates);
        $status = $campaign->getAttribute('status');
        $sendMode = $campaign->getAttribute('send_mode');
        // Completed campaigns stay completed: new enrolments queue until Resume.
        $activate = $this->isCampaignStatus($status, CampaignStatus::Active);
        $pin = $this->isSendMode($sendMode, SendMode::Pin);
        $enrolled = 0;
        $skipped = [];

        foreach ($candidates as $candidate) {
            $skip = $this->skipReason($candidate, $enrolledEmails, $enrolledPersonIds, $suppressed);

            if ($skip !== null) {
                $skipped[] = $this->skip($candidate, $skip);

                continue;
            }

            $mailbox = $pin
                ? $mailboxes->first()
                : $mailboxes->values()->get(($existing + $enrolled) % max($mailboxes->count(), 1));

            if (! $mailbox instanceof Mailbox && ! $allowUnassigned) {
                throw ValidationException::withMessages([
                    'person_ids' => 'Assign a mailbox from this workspace before importing recipients.',
                ]);
            }

            try {
                CampaignRecipient::query()->create([
                    'campaign_id' => $campaign->id,
                    'mailbox_id' => $mailbox?->id,
                    'sequence_version_id' => $campaign->getAttribute('published_sequence_version_id')
                        ?? $campaign->getAttribute('draft_sequence_version_id'),
                    'workspace_id' => $workspaceId,
                    'person_id' => $candidate->personId,
                    'account_id' => $candidate->accountId,
                    'email_address' => $candidate->emailAddress,
                    'email_normalized' => $candidate->emailNormalized,
                    'email_domain' => EmailNormalizer::domain($candidate->emailNormalized),
                    'unsubscribe_token' => (string) Str::uuid(),
                    'full_name' => $candidate->fullName,
                    'first_name' => $candidate->firstName,
                    'company' => $candidate->company,
                    'status' => $activate ? RecipientStatus::Active : RecipientStatus::Queued,
                    'mailbox_assignment' => $pin
                        ? MailboxAssignment::CampaignPin
                        : MailboxAssignment::Rotation,
                    'next_sequence_step_id' => $this->firstStepId($campaign),
                    'next_send_at' => $activate ? now() : null,
                ]);
            } catch (UniqueConstraintViolationException) {
                $skipped[] = $this->skip($candidate, self::REASON_ALREADY_ENROLLED);

                continue;
            }

            $enrolled++;
            $enrolledEmails[$candidate->emailNormalized] = true;

            if ($candidate->personId !== null) {
                $enrolledPersonIds[$candidate->personId] = true;
            }
        }

        return new EnrollmentResult($enrolled, $skipped);
    }

    /**
     * Enrol the compiler's exact, already selected Person/mailbox snapshots.
     * This is deliberately separate from CSV/registry enrolment so ordinary
     * campaign callers cannot choose hidden governed content.
     *
     * @param  list<GovernedCampaignAction>  $candidates
     * @return array<string, CampaignRecipient>
     */
    public function handleGoverned(
        Campaign $campaign,
        SequenceVersion $version,
        SequenceStep $step,
        array $candidates,
        GovernedCampaignCompilationCapability $capability,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Governed enrolment requires the compiler transaction.');
        }

        $this->governedInputs->assertCompilerCampaign($capability, $campaign);

        $this->frozenInputs->assertCampaignAudienceMutable($campaign);
        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        if ((int) $version->getAttribute('campaign_id') !== (int) $campaign->getKey()
            || (int) $step->getAttribute('sequence_version_id') !== (int) $version->getKey()
            || $candidates === []) {
            throw ValidationException::withMessages([
                'actions' => 'The governed Campaign graph does not match its exact action set.',
            ]);
        }

        $mailboxIds = collect($candidates)->pluck('mailboxId')->map(intval(...))->unique()->sort()->values();
        $attachedIds = $campaign->mailboxes()
            ->where('mailboxes.workspace_id', $workspaceId)
            ->whereIn('mailboxes.id', $mailboxIds->all())
            ->pluck('mailboxes.id')
            ->map(intval(...))
            ->sort()
            ->values();
        if ($attachedIds->all() !== $mailboxIds->all()) {
            throw ValidationException::withMessages([
                'mailboxes' => 'Every approved mailbox must be attached to the governed Campaign.',
            ]);
        }

        $mailboxes = Mailbox::query()->where('workspace_id', $workspaceId)
            ->whereIn('id', $mailboxIds->all())->get()->keyBy('id');
        $enrolledEmails = $this->existingEmails($campaign);
        $enrolledPersonIds = $this->existingPersonIds($campaign);
        $baseCandidates = array_map(
            static fn (GovernedCampaignAction $candidate): EnrollmentCandidate => new EnrollmentCandidate(
                personId: $candidate->personId,
                accountId: $candidate->accountId,
                emailAddress: $candidate->emailAddress,
                emailNormalized: $candidate->emailNormalized,
                fullName: $candidate->fullName,
                firstName: $candidate->firstName,
                company: $candidate->company,
            ),
            $candidates,
        );
        $suppressed = $this->suppressedEmails($workspaceId, $baseCandidates);
        $recipients = [];

        foreach ($candidates as $index => $candidate) {
            $base = $baseCandidates[$index];
            $skip = $this->skipReason($base, $enrolledEmails, $enrolledPersonIds, $suppressed);
            if ($skip !== null) {
                throw ValidationException::withMessages([
                    'actions' => "The approved recipient is no longer eligible: {$skip}.",
                ]);
            }
            $mailbox = $mailboxes->get($candidate->mailboxId);
            if (! $mailbox instanceof Mailbox) {
                throw ValidationException::withMessages([
                    'mailboxes' => 'An approved mailbox is no longer available.',
                ]);
            }

            try {
                $recipient = CampaignRecipient::query()->create([
                    'campaign_id' => $campaign->id,
                    'mailbox_id' => $mailbox->id,
                    'sequence_version_id' => $version->id,
                    'workspace_id' => $workspaceId,
                    'person_id' => $candidate->personId,
                    'account_id' => $candidate->accountId,
                    'email_address' => $candidate->emailAddress,
                    'email_normalized' => $candidate->emailNormalized,
                    'email_domain' => EmailNormalizer::domain($candidate->emailNormalized),
                    'unsubscribe_token' => (string) Str::uuid(),
                    'full_name' => $candidate->fullName,
                    'first_name' => $candidate->firstName,
                    'company' => $candidate->company,
                    'status' => RecipientStatus::Queued,
                    'mailbox_assignment' => MailboxAssignment::ManualPin,
                    'next_sequence_step_id' => $step->id,
                    'next_send_at' => null,
                    'governed_subject_snapshot' => $candidate->subject,
                    'governed_body_text_snapshot' => $candidate->bodyText,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'actions' => 'An approved recipient is already enrolled in the governed Campaign.',
                ]);
            }

            $budget = $this->sendBudget->canSchedule($mailbox, $recipient);
            if (! $budget->allowed) {
                throw ValidationException::withMessages([
                    'mailboxes' => 'An approved mailbox is not currently admitted by the send budget.',
                ]);
            }

            $recipients[$candidate->actionId] = $recipient;
            $enrolledEmails[$candidate->emailNormalized] = true;
            $enrolledPersonIds[$candidate->personId] = true;
        }

        return $recipients;
    }

    /**
     * @param  list<EnrollmentCandidate>  $candidates
     * @return array<string, true>
     */
    private function suppressedEmails(int $workspaceId, array $candidates): array
    {
        $emails = array_values(array_unique(array_map(
            fn (EnrollmentCandidate $candidate): string => $candidate->emailNormalized,
            $candidates,
        )));

        if ($emails === []) {
            return [];
        }

        $set = [];

        foreach (SuppressionEntry::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('email_normalized', $emails)
            ->pluck('email_normalized') as $email) {
            $set[(string) $email] = true;
        }

        return $set;
    }

    /**
     * @return array<string, true>
     */
    private function existingEmails(Campaign $campaign): array
    {
        $set = [];

        foreach ($campaign->recipients()->pluck('email_normalized') as $email) {
            if ($email === null || $email === '') {
                continue;
            }

            $set[(string) $email] = true;
        }

        return $set;
    }

    /**
     * @return array<int, true>
     */
    private function existingPersonIds(Campaign $campaign): array
    {
        $set = [];

        foreach ($campaign->recipients()->whereNotNull('person_id')->pluck('person_id') as $id) {
            $set[(int) $id] = true;
        }

        return $set;
    }

    /**
     * @param  array<string, true>  $enrolledEmails
     * @param  array<int, true>  $enrolledPersonIds
     * @param  array<string, true>  $suppressed
     */
    private function skipReason(
        EnrollmentCandidate $candidate,
        array $enrolledEmails,
        array $enrolledPersonIds,
        array $suppressed,
    ): ?string {
        if (! EmailNormalizer::isValid($candidate->emailAddress)) {
            return self::REASON_INVALID;
        }

        if (isset($suppressed[$candidate->emailNormalized])) {
            return self::REASON_SUPPRESSED;
        }

        if (isset($enrolledEmails[$candidate->emailNormalized])) {
            return self::REASON_ALREADY_ENROLLED;
        }

        if ($candidate->personId !== null && isset($enrolledPersonIds[$candidate->personId])) {
            return self::REASON_ALREADY_ENROLLED;
        }

        return null;
    }

    private function skip(EnrollmentCandidate $candidate, string $reason): EnrollmentSkip
    {
        $name = trim((string) $candidate->fullName);

        return new EnrollmentSkip(
            $candidate->personId,
            $name !== '' ? $name : $candidate->emailAddress,
            $reason,
        );
    }

    private function firstStepId(Campaign $campaign): ?int
    {
        foreach ([$campaign->publishedVersion, $campaign->draftVersion] as $version) {
            if (! $version instanceof SequenceVersion) {
                continue;
            }

            $step = $version->activeSteps->first();

            if ($step instanceof SequenceStep) {
                return $step->id;
            }
        }

        return null;
    }

    private function isCampaignStatus(mixed $status, CampaignStatus $expected): bool
    {
        return $status === $expected || $status === $expected->value;
    }

    private function isSendMode(mixed $mode, SendMode $expected): bool
    {
        return $mode === $expected || $mode === $expected->value;
    }
}
