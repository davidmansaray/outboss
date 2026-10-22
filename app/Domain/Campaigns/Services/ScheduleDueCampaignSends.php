<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Support\SendWindowCalculator;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Domain\Mail\Services\MailExperimentRuntimeGuard;
use App\Domain\Mail\Services\ManagedMailCapability;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\Mail\Support\HtmlSanitizer;
use App\Domain\Objectives\Enums\ExecutionActionEventType;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Domain\Objectives\Enums\PolicyEvaluationStage;
use App\Domain\Objectives\Services\RecordExecutionActionEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\OutboxEvent;
use App\Models\ProviderConnection;
use App\Models\SendAttempt;
use App\Models\SequenceStep;
use App\Models\SuppressionEntry;
use App\Models\Thread;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ScheduleDueCampaignSends
{
    public function __construct(
        private SendWindowCalculator $windows,
        private RenderSequenceMessage $renderer,
        private HtmlSanitizer $sanitizer,
        private MailboxSenderIdentityService $senderIdentities,
        private MailboxSendBudget $budget,
        private ManagedMailCapability $managedMail,
        private MailExperimentRuntimeGuard $experimentGuard,
        private GovernedCampaignExecutionGuard $governedExecution,
        private RecordExecutionActionEvent $actionEvents,
        private GovernedCampaignInputGuard $governedInputs,
    ) {}

    public function handle(): int
    {
        if (! config('outboss.campaign_sending_enabled')) {
            return 0;
        }

        $created = 0;

        Campaign::query()
            ->where('status', CampaignStatus::Active)
            ->each(function (Campaign $campaign) use (&$created): void {
                $created += $this->scheduleCampaign($campaign);
            });

        return $created;
    }

    private function scheduleCampaign(Campaign $campaign): int
    {
        if (! $this->managedMail->campaignRuntimeAllows($campaign)) {
            return 0;
        }

        $created = 0;

        CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', RecipientStatus::Active)
            ->whereNotNull('next_sequence_step_id')
            ->where('next_send_at', '<=', now())
            ->orderBy('id')
            ->each(function (CampaignRecipient $recipient) use ($campaign, &$created): void {
                if ($this->scheduleRecipient($campaign, $recipient)) {
                    $created++;
                }
            });

        return $created;
    }

    private function scheduleRecipient(Campaign $campaign, CampaignRecipient $recipient): bool
    {
        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        $managedMail = $this->managedMail;
        $experimentGuard = $this->experimentGuard;

        return (bool) DB::transaction(function () use ($campaign, $recipient, $workspaceId, $managedMail, $experimentGuard) {
            // Resolve the candidate without a row lock first. The guardrail
            // pause locks the experiment before recipients; taking this lock
            // first would allow a scheduler and pause worker to deadlock.
            $candidate = CampaignRecipient::query()->whereKey($recipient->id)->first();

            if (! $candidate || ! $candidate->next_sequence_step_id) {
                return false;
            }

            if (! $managedMail->campaignRuntimeAllows($campaign)) {
                return false;
            }

            // Establish the experiment lock before any campaign/mailbox lock;
            // the runtime guard can then pause queued cohort work atomically.
            $preflight = $experimentGuard->check($campaign);
            if (! $preflight->allowed) {
                return false;
            }

            $recipient = CampaignRecipient::query()->whereKey($candidate->id)->lockForUpdate()->first();

            if (! $recipient || ! $recipient->next_sequence_step_id) {
                return false;
            }

            // Scheduling reserves capacity across every mailbox in a
            // campaign. Lock the campaign before evaluating the shared
            // campaign target, matching the lease/provider-boundary paths.
            $campaign = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->first();
            if (! $campaign instanceof Campaign) {
                return false;
            }

            if ($workspaceId <= 0
                || (int) $recipient->getAttribute('workspace_id') !== $workspaceId
                || (int) $recipient->getAttribute('campaign_id') !== (int) $campaign->id) {
                $this->blockForWorkspaceMismatch($recipient, $workspaceId);

                return false;
            }

            if ($recipient->getAttribute('status') !== RecipientStatus::Active) {
                return false;
            }

            // Keep the managed identity topology stable through scheduling;
            // the experiment guard already owns the experiment lock before
            // this mailbox lock is taken.
            $mailbox = Mailbox::query()->whereKey($recipient->mailbox_id)->lockForUpdate()->first();

            if (! $mailbox) {
                return false;
            }

            if ((int) $mailbox->getAttribute('workspace_id') !== $workspaceId) {
                $this->blockForWorkspaceMismatch($recipient, $workspaceId);

                return false;
            }

            $connection = ProviderConnection::query()->find($mailbox->provider_connection_id);

            if ($connection === null || (int) $connection->getAttribute('workspace_id') !== $workspaceId) {
                $this->blockForWorkspaceMismatch($recipient, $workspaceId);

                return false;
            }

            $runtime = $experimentGuard->check($campaign, $mailbox, $recipient);
            if (! $runtime->allowed) {
                return false;
            }

            // Validate lineage before maintenance writes or rendering. A corrupt
            // mapping cannot receive even a narrow runtime mutation capability.
            // Keep the final checkpoint below as well, immediately before sends.
            if (! $this->governedExecution->forRecipient($recipient, PolicyEvaluationStage::Schedule)->allowed()) {
                return false;
            }

            $suppressed = SuppressionEntry::query()
                ->where('workspace_id', $workspaceId)
                ->where('email_normalized', $recipient->email_normalized)
                ->exists();

            if ($mailbox->getAttribute('status') !== ConnectionStatus::Connected
                || $mailbox->getAttribute('sending_paused_at') !== null
                || $suppressed) {
                return false;
            }

            if (! $recipient->getAttribute('email_domain')) {
                $this->saveRecipient($recipient, [
                    'email_domain' => EmailNormalizer::domain($recipient->email_normalized),
                ]);
            }

            $decision = $this->budget->canSchedule($mailbox, $recipient, $campaign);

            if (! $decision->allowed && $decision->retryAt !== null) {
                $this->saveRecipient($recipient, [
                    'next_send_at' => $this->windows->nextAllowed(
                        $decision->retryAt,
                        $campaign->timezone,
                        (string) $campaign->send_window_start,
                        (string) $campaign->send_window_end,
                        $campaign->business_days_only,
                    ),
                ]);

                return false;
            }

            $nextSendAt = $recipient->getAttribute('next_send_at');
            $due = $this->windows->nextAllowed(
                $nextSendAt instanceof DateTimeInterface ? $nextSendAt : now(),
                $campaign->timezone,
                (string) $campaign->send_window_start,
                (string) $campaign->send_window_end,
                $campaign->business_days_only,
            );

            if ($due->isAfter(now())) {
                $this->saveRecipient($recipient, ['next_send_at' => $due]);

                return false;
            }

            $step = SequenceStep::query()
                ->whereKey($recipient->next_sequence_step_id)
                ->whereHas('version', fn ($version) => $version->where('campaign_id', $campaign->id))
                ->first();

            if (! $step) {
                return false;
            }

            $existing = SendAttempt::query()
                ->where('campaign_recipient_id', $recipient->id)
                ->where('sequence_step_id', $step->id)
                ->where('kind', SendAttemptKind::Campaign)
                ->lockForUpdate()
                ->first();

            if ($existing && (int) $existing->getAttribute('workspace_id') !== $workspaceId) {
                $this->blockForWorkspaceMismatch($recipient, $workspaceId);

                return false;
            }

            $existingMessage = Message::query()->lockForUpdate()->find($existing?->message_id);

            if ($existingMessage && ((int) $existingMessage->getAttribute('workspace_id') !== $workspaceId
                || (int) $existingMessage->getAttribute('mailbox_id') !== (int) $mailbox->id
                || (int) $existingMessage->getAttribute('campaign_recipient_id') !== (int) $recipient->id)) {
                $this->blockForWorkspaceMismatch($recipient, $workspaceId);

                return false;
            }

            if ($existing && ! in_array($existing->getAttribute('status'), [SendAttemptStatus::Scheduled, SendAttemptStatus::Queued, SendAttemptStatus::Deferred, SendAttemptStatus::Failed], true)) {
                return false;
            }

            try {
                $rendered = $this->renderer->handle($step, $recipient);
            } catch (InvalidArgumentException) {
                return false;
            }
            $parent = null;

            if ($step->getAttribute('send_as') === SequenceSendAs::Reply) {
                $parent = Message::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('campaign_recipient_id', $recipient->id)
                    ->where('direction', MessageDirection::Outbound)
                    ->whereIn('status', [MessageStatus::ProviderAccepted, MessageStatus::Sent])
                    ->orderByDesc('id')
                    ->first();

                if ($parent) {
                    $rendered['subject'] = $parent->subject;
                }
            }

            $sender = null;

            if (! $existingMessage) {
                try {
                    $sender = $this->senderIdentities->forReply($mailbox);
                } catch (SenderIdentityUnavailable) {
                    $this->blockForSenderIdentity($recipient);

                    return false;
                }
            }

            $threadId = $existingMessage
                ? $existingMessage->thread_id
                : ($parent ? $parent->thread_id : null);
            $thread = $threadId ? Thread::query()->lockForUpdate()->find($threadId) : null;

            if ($thread && ((int) $thread->getAttribute('workspace_id') !== $workspaceId
                || (int) $thread->getAttribute('mailbox_id') !== (int) $mailbox->id
                || ($thread->getAttribute('campaign_recipient_id') !== null
                    && (int) $thread->getAttribute('campaign_recipient_id') !== (int) $recipient->id))) {
                $this->blockForWorkspaceMismatch($recipient, $workspaceId);

                return false;
            }

            // This is the final exact checkpoint in the scheduler transaction,
            // immediately before any Thread, Message, attempt, or outbox row.
            $governed = $this->governedExecution->forRecipient(
                $recipient,
                PolicyEvaluationStage::Schedule,
                $step,
            );
            if (! $governed->allowed()) {
                return false;
            }

            $thread ??= Thread::query()->create([
                'user_id' => $campaign->user_id,
                'workspace_id' => $workspaceId,
                'mailbox_id' => $mailbox->id,
                'campaign_recipient_id' => $recipient->id,
                'local_key' => (string) Str::uuid(),
                'subject' => EmailNormalizer::stripHeaderInjection($rendered['subject']),
                'subject_normalized' => EmailNormalizer::normalizeSubject($rendered['subject']),
                'participant_email' => $recipient->email_normalized,
                'participant_name' => $recipient->full_name,
                'classification' => ThreadClassification::Unknown,
                'local_state' => ThreadLocalState::Open,
            ]);

            $message = $existingMessage ?? Message::query()->create([
                'workspace_id' => $workspaceId,
                'local_key' => (string) Str::uuid(),
                'thread_id' => $thread->id,
                'mailbox_id' => $mailbox->id,
                'campaign_recipient_id' => $recipient->id,
                'sequence_step_id' => $step->id,
                'reply_to_message_id' => $parent?->id,
                'client_message_marker' => (string) Str::uuid(),
                'tracking_token' => $campaign->open_tracking_enabled ? (string) Str::uuid() : null,
                'direction' => MessageDirection::Outbound,
                'status' => MessageStatus::Queued,
                'from_email' => $sender['email'],
                'from_name' => $sender['name'],
                'to_recipients' => [['email' => $recipient->email_address]],
                'cc_recipients' => [],
                'bcc_recipients' => [],
                'subject' => EmailNormalizer::stripHeaderInjection($rendered['subject']),
                'body_text' => $rendered['body'],
                'body_html_sanitized' => $this->sanitizer->sanitize(nl2br(e($rendered['body']))),
                'rendered_payload' => json_encode($rendered),
                'provider_metadata' => [],
            ]);

            $wasNew = ! $existing || in_array($existing->getAttribute('status'), [SendAttemptStatus::Deferred, SendAttemptStatus::Failed], true);

            $attempt = $existing ?? SendAttempt::query()->firstOrCreate(
                [
                    'campaign_recipient_id' => $recipient->id,
                    'sequence_step_id' => $step->id,
                    'kind' => SendAttemptKind::Campaign,
                ],
                [
                    'user_id' => $campaign->user_id,
                    'workspace_id' => $workspaceId,
                    'mailbox_id' => $mailbox->id,
                    'message_id' => $message->id,
                    'idempotency_key' => (string) Str::uuid(),
                    'status' => SendAttemptStatus::Scheduled,
                    'due_at' => $due,
                ],
            );

            if ((int) $attempt->getAttribute('workspace_id') !== $workspaceId
                || (int) $attempt->getAttribute('mailbox_id') !== (int) $mailbox->id
                || (int) $attempt->getAttribute('message_id') !== (int) $message->id) {
                $this->blockForWorkspaceMismatch($recipient, $workspaceId);

                return false;
            }

            $attempt->forceFill([
                'status' => SendAttemptStatus::Queued,
                'due_at' => $due,
                'deferred_reason' => null,
            ])->save();

            if ($governed->governed && $governed->action !== null) {
                $this->actionEvents->handle(
                    $governed->action,
                    ExecutionActionEventType::Queued,
                    $recipient,
                    $message,
                    $attempt,
                );
                DB::table('execution_plan_actions')
                    ->where('workspace_id', $workspaceId)
                    ->where('id', $governed->action->id)
                    ->whereIn('state', [
                        ExecutionPlanActionState::Compiled->value,
                        ExecutionPlanActionState::Queued->value,
                    ])->update([
                        'state' => ExecutionPlanActionState::Queued->value,
                        'updated_at' => now(),
                    ]);
            }

            if ($wasNew) {
                $mailbox = Mailbox::query()->whereKey($mailbox->id)->lockForUpdate()->firstOrFail();
                $availableAt = $this->budget->nextSpacingSlot($mailbox);
                $mailbox->forceFill(['next_send_slot_at' => $availableAt])->save();

                OutboxEvent::query()->create([
                    'type' => 'send_attempt.created',
                    'aggregate_type' => 'send_attempt',
                    'aggregate_id' => $attempt->id,
                    'payload' => ['send_attempt_id' => $attempt->id],
                    'available_at' => $availableAt,
                ]);
            }

            return true;
        });
    }

    private function blockForSenderIdentity(CampaignRecipient $recipient): void
    {
        $remaining = $recipient->next_send_at
            ? max(0, now()->diffInSeconds($recipient->next_send_at, false))
            : $recipient->remaining_delay_seconds;

        $this->saveRecipient($recipient, [
            'blocked_from_status' => RecipientStatus::Active->value,
            'blocked_at' => now(),
            'remaining_delay_seconds' => $remaining,
            'status' => RecipientStatus::BlockedMailbox,
        ]);
    }

    private function blockForWorkspaceMismatch(CampaignRecipient $recipient, int $workspaceId): void
    {
        if ($workspaceId <= 0 || (int) $recipient->getAttribute('workspace_id') !== $workspaceId) {
            return;
        }

        $remaining = $recipient->next_send_at
            ? max(0, now()->diffInSeconds($recipient->next_send_at, false))
            : $recipient->remaining_delay_seconds;

        $this->saveRecipient($recipient, [
            'blocked_from_status' => RecipientStatus::Active->value,
            'blocked_at' => now(),
            'remaining_delay_seconds' => $remaining,
            'status' => RecipientStatus::BlockedMailbox,
            'stop_reason' => 'workspace_mismatch',
        ]);
    }

    /** @param array<string,mixed> $attributes */
    private function saveRecipient(CampaignRecipient $recipient, array $attributes): void
    {
        $this->governedInputs->runRecipientExecutionState(
            $recipient,
            static function () use ($recipient, $attributes): void {
                $recipient->forceFill($attributes)->save();
            },
        );
    }
}
