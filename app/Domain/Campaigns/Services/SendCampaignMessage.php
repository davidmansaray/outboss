<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\DTOs\OutgoingMessage;
use App\Domain\Mail\DTOs\RemoteMessageReference;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Providers\ProviderRegistry;
use App\Domain\Mail\Providers\StalwartJmapException;
use App\Domain\Mail\Services\EnsureConnectionUsable;
use App\Domain\Mail\Services\EvaluateMailboxSendingHealth;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Domain\Mail\Services\MailExperimentRuntimeGuard;
use App\Domain\Mail\Services\ManagedMailCapability;
use App\Domain\Objectives\Enums\ExecutionActionEventType;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Domain\Objectives\Enums\PolicyEvaluationStage;
use App\Domain\Objectives\Services\RecordExecutionActionEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ExecutionPlanAction;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\ProviderConnection;
use App\Models\SendAttempt;
use App\Models\SequenceStep;
use App\Models\SuppressionEntry;
use App\Models\Thread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class SendCampaignMessage
{
    public function __construct(
        private AcquireRecipientSendLease $leases,
        private ProviderRegistry $registry,
        private EnsureConnectionUsable $tokens,
        private MailboxSenderIdentityService $senderIdentities,
        private EvaluateMailboxSendingHealth $health,
        private ManagedMailCapability $managedMail,
        private MailExperimentRuntimeGuard $experimentGuard,
        private FinalizeCampaignSend $finalizer,
        private MailboxSendBudget $budget,
        private GovernedCampaignExecutionGuard $governedExecution,
        private RecordExecutionActionEvent $actionEvents,
        private GovernedCampaignInputGuard $governedInputs,
    ) {}

    public function handle(int $attemptId): void
    {
        $attempt = SendAttempt::query()->with(['recipient.campaign', 'mailbox.connection', 'sequenceStep'])->find($attemptId);

        if (! $attempt) {
            return;
        }

        // A queued experiment attempt may outlive a lifecycle transition.
        // Check the authoritative experiment link before acquiring a send
        // lease so a paused experiment leaves the attempt safely retryable.
        $initialRecipientId = $attempt->getAttribute('campaign_recipient_id');
        $initialRecipient = is_numeric($initialRecipientId)
            ? CampaignRecipient::query()->with('campaign')->find((int) $initialRecipientId)
            : null;

        if (! $this->managedMail->campaignRuntimeAllows($initialRecipient?->campaign)) {
            return;
        }

        if (! in_array($attempt->getAttribute('status'), [
            SendAttemptStatus::Scheduled,
            SendAttemptStatus::Queued,
            SendAttemptStatus::Deferred,
            SendAttemptStatus::Failed,
        ], true)) {
            return;
        }

        $leased = $this->leases->handle($attempt);

        if (! $leased) {
            return;
        }

        $mailbox = Mailbox::query()->find($leased->mailbox_id);
        $connection = $mailbox
            ? ProviderConnection::query()->find($mailbox->provider_connection_id)
            : null;
        $message = Message::query()->find($leased->message_id);
        $recipient = CampaignRecipient::query()->with('campaign')->find($leased->campaign_recipient_id);
        $parentId = $message?->getAttribute('reply_to_message_id');
        $parent = $parentId === null
            ? null
            : Message::query()->whereKey((int) $parentId)->first();
        $parentThread = $parent === null
            ? null
            : Thread::query()->whereKey((int) $parent->getAttribute('thread_id'))->first();

        if (! $mailbox || ! $connection || ! $message || ! $recipient
            || ! $this->workspaceConsistent($leased, $mailbox, $connection, $message, $recipient, $parent, $parentThread)) {
            $this->failForWorkspaceMismatch($leased, $recipient);

            return;
        }

        // Close the small race between the pre-lease check and the lease
        // transaction. If an experiment was paused/disabled while the lease
        // was being acquired, release it without advancing the recipient or
        // consuming a provider attempt.
        $runtime = $this->experimentGuard->check($recipient->campaign, $mailbox, $recipient);
        if (! $runtime->allowed) {
            $this->releaseExperimentLease($leased, $recipient, $runtime->experimentPaused, $runtime->reason);

            return;
        }

        $expectedLeaseToken = (string) $recipient->send_lease_token;

        try {
            $this->senderIdentities->assertSnapshotAllowed($mailbox, $message->from_email);
        } catch (SenderIdentityUnavailable) {
            $this->deferForSenderIdentity($leased);

            return;
        }

        $this->tokens->handle($connection);
        $connection->refresh();

        $key = 'send-mailbox:'.$mailbox->id;
        if (RateLimiter::tooManyAttempts($key, (int) config('outboss.send_rate_per_minute', 10))) {
            $this->releaseExperimentLease($leased, $recipient, true, 'rate_limited');

            return;
        }

        $runtime = $this->experimentGuard->check($recipient->campaign, $mailbox, $recipient);
        if (! $runtime->allowed) {
            $this->releaseExperimentLease($leased, $recipient, $runtime->experimentPaused, $runtime->reason);

            return;
        }

        $governedAction = null;
        if (! $this->prepareProviderSend($leased, $expectedLeaseToken, $governedAction)) {
            return;
        }

        $leased->refresh();
        $mailbox->refresh();
        $connection->refresh();
        $message->refresh();
        $recipient->refresh();

        // Count only work that passed every final locked safety checkpoint.
        RateLimiter::hit($key, 60);

        $html = $this->withTrackingPixel($message->body_html_sanitized ?: nl2br(e($message->body_text)), $message->tracking_token);
        $unsubscribeUrl = $recipient->unsubscribe_token
            ? route('unsubscribe.store', $recipient->unsubscribe_token)
            : null;
        $outgoing = new OutgoingMessage(
            subject: $message->subject,
            bodyText: $message->body_text,
            bodyHtml: $html,
            to: $message->outboundRecipients(),
            fromEmail: $message->from_email,
            fromName: $message->from_name,
            clientMessageMarker: $message->client_message_marker,
            inReplyTo: $parent?->internet_message_id,
            trackingToken: $message->tracking_token,
            listUnsubscribeUrl: $unsubscribeUrl,
            oneClickPost: $unsubscribeUrl !== null,
        );

        try {
            $provider = $this->registry->transport($connection->provider);
            $result = $parent?->remote_message_id
                ? $provider->sendReply($mailbox, new RemoteMessageReference(
                    remoteMessageId: $parent->remote_message_id,
                    remoteThreadId: $parentThread?->remote_thread_id,
                    internetMessageId: $parent->internet_message_id,
                    subject: $parent->subject,
                ), $outgoing)
                : $provider->sendNew($mailbox, $outgoing);
        } catch (Throwable $exception) {
            $attributes = [
                'status' => SendAttemptStatus::Unknown,
                'error_code' => 'provider_timeout',
                'safe_error_message' => mb_substr($exception->getMessage(), 0, 180),
                'provider_unknown_count' => (int) $leased->getAttribute('provider_unknown_count') + 1,
                'provider_error_count' => (int) $leased->getAttribute('provider_error_count') + 1,
            ];
            $evidence = $exception instanceof StalwartJmapException ? $exception->metadata : [];

            if (($evidence['submission_state'] ?? null) === 'not_created') {
                $attributes['provider_phase'] = 'email_created_not_submitted';
                $attributes['error_code'] = 'stalwart_submission_not_created';
                $attributes['safe_error_message'] = 'The email copy was created, but the provider did not accept its submission.';

                if (is_string($evidence['email_id'] ?? null) && $evidence['email_id'] !== '') {
                    $attributes['provider_draft_id'] = $evidence['email_id'];
                }

                if (is_string($evidence['client_message_marker'] ?? null) && $evidence['client_message_marker'] !== '') {
                    $attributes['provider_marker'] = $evidence['client_message_marker'];
                }
            }

            $leased->forceFill($attributes)->save();
            if ($governedAction instanceof ExecutionPlanAction) {
                $this->actionEvents->handle(
                    $governedAction,
                    ExecutionActionEventType::Uncertain,
                    $recipient,
                    $message,
                    $leased->fresh(),
                    (string) $attributes['error_code'],
                );
                DB::table('execution_plan_actions')->where('id', $governedAction->id)->update([
                    'state' => ExecutionPlanActionState::Uncertain->value,
                    'updated_at' => now(),
                ]);
            }
            $this->health->handle($mailbox);

            return;
        }

        $this->finalizer->handle($leased, $result, bodyHtml: $html);
    }

    private function prepareProviderSend(
        SendAttempt $attempt,
        string $expectedLeaseToken,
        ?ExecutionPlanAction &$governedAction,
    ): bool {
        return (bool) DB::transaction(function () use ($attempt, $expectedLeaseToken, &$governedAction): bool {
            $attempt = SendAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();
            if (! $attempt instanceof SendAttempt
                || $attempt->getAttribute('status') !== SendAttemptStatus::LeaseAcquired) {
                return false;
            }
            $recipient = CampaignRecipient::query()->whereKey($attempt->getAttribute('campaign_recipient_id'))->lockForUpdate()->first();
            if (! $recipient instanceof CampaignRecipient
                || $expectedLeaseToken === ''
                || ! hash_equals($expectedLeaseToken, (string) $recipient->getAttribute('send_lease_token'))
                || $recipient->getAttribute('send_lease_expires_at') === null
                || ! now()->lessThan($recipient->getAttribute('send_lease_expires_at'))) {
                $this->deferLocked($attempt, $recipient, 'stale_send_lease', $expectedLeaseToken);

                return false;
            }
            $campaign = Campaign::query()->whereKey($recipient->getAttribute('campaign_id'))->lockForUpdate()->first();
            $mailbox = Mailbox::query()->whereKey($attempt->getAttribute('mailbox_id'))->lockForUpdate()->first();
            $message = Message::query()->whereKey($attempt->getAttribute('message_id'))->lockForUpdate()->first();
            $parentId = $message?->getAttribute('reply_to_message_id');
            $parent = is_numeric($parentId)
                ? Message::query()->whereKey((int) $parentId)->first()
                : null;
            $parentThread = $parent instanceof Message
                ? Thread::query()->whereKey((int) $parent->getAttribute('thread_id'))->first()
                : null;
            $connection = $mailbox instanceof Mailbox
                ? ProviderConnection::query()->whereKey($mailbox->getAttribute('provider_connection_id'))->first()
                : null;
            if (! $campaign instanceof Campaign || ! $mailbox instanceof Mailbox
                || ! $message instanceof Message || ! $connection instanceof ProviderConnection
                || ! $this->workspaceConsistent($attempt, $mailbox, $connection, $message, $recipient, $parent, $parentThread)) {
                $this->failForWorkspaceMismatch($attempt, $recipient);

                return false;
            }
            if (! config('outboss.campaign_sending_enabled')) {
                $this->deferLocked($attempt, $recipient, 'kill_switch');

                return false;
            }
            if (! $this->managedMail->campaignRuntimeAllows($campaign)) {
                $this->deferLocked($attempt, $recipient, 'managed_mail_blocked');

                return false;
            }
            $runtime = $this->experimentGuard->check($campaign, $mailbox, $recipient);
            if (! $runtime->allowed) {
                $this->deferLocked($attempt, $recipient, $runtime->reason ?? 'experiment_blocked');

                return false;
            }
            $suppressed = SuppressionEntry::query()
                ->where('workspace_id', $campaign->getAttribute('workspace_id'))
                ->where('email_normalized', $recipient->getAttribute('email_normalized'))
                ->exists();
            if ($campaign->getAttribute('status') !== CampaignStatus::Active
                || $recipient->getAttribute('status') !== RecipientStatus::Active
                || $mailbox->getAttribute('status') !== ConnectionStatus::Connected
                || $mailbox->getAttribute('sending_paused_at') !== null
                || $suppressed) {
                $this->deferLocked($attempt, $recipient, $suppressed ? 'recipient_suppressed' : 'preflight');

                return false;
            }
            try {
                $this->senderIdentities->assertSnapshotAllowed($mailbox, $message->getAttribute('from_email'));
            } catch (SenderIdentityUnavailable) {
                $this->deferLocked($attempt, $recipient, 'sender_identity_unavailable');

                return false;
            }
            // This is the provider-boundary check under the campaign lock;
            // it is the final cross-mailbox campaign target checkpoint.
            $budget = $this->budget->gate($mailbox, $recipient, $campaign);
            if (! $budget->allowed) {
                $this->deferLocked($attempt, $recipient, $budget->deferredReason);

                return false;
            }

            // The last provider-boundary checkpoint runs under the live attempt,
            // recipient, Campaign and mailbox locks and before `Sending` is set.
            $step = SequenceStep::query()->whereKey($attempt->getAttribute('sequence_step_id'))->first();
            $governed = $this->governedExecution->forRecipient(
                $recipient,
                PolicyEvaluationStage::PreSend,
                $step,
            );
            if (! $step instanceof SequenceStep && $governed->governed) {
                $this->deferLocked($attempt, $recipient, 'governed_action_missing');

                return false;
            }
            if (! $governed->allowed()) {
                $this->deferLocked($attempt, $recipient, $governed->primaryCode());

                return false;
            }

            $attempt->forceFill([
                'status' => SendAttemptStatus::Sending,
                'provider_phase' => 'send_submitted',
                'attempt_count' => (int) $attempt->getAttribute('attempt_count') + 1,
                'provider_attempted_at' => $attempt->getAttribute('provider_attempted_at') ?? now(),
            ])->save();
            if ($governed->governed && $governed->action instanceof ExecutionPlanAction) {
                $governedAction = $governed->action;
                $this->actionEvents->handle(
                    $governedAction,
                    ExecutionActionEventType::Attempted,
                    $recipient,
                    $message,
                    $attempt,
                );
                DB::table('execution_plan_actions')->where('id', $governedAction->id)->update([
                    'state' => ExecutionPlanActionState::Attempted->value,
                    'updated_at' => now(),
                ]);
            }

            return true;
        }, 3);
    }

    private function deferLocked(
        SendAttempt $attempt,
        ?CampaignRecipient $recipient,
        string $reason,
        ?string $expectedLeaseToken = null,
    ): void {
        $attempt->forceFill([
            'status' => SendAttemptStatus::Deferred,
            'provider_phase' => null,
            'deferred_reason' => $reason,
        ])->save();
        if ($recipient instanceof CampaignRecipient
            && ($expectedLeaseToken === null
                || ($expectedLeaseToken !== ''
                    && hash_equals($expectedLeaseToken, (string) $recipient->getAttribute('send_lease_token'))))) {
            $this->governedInputs->runRecipientExecutionState($recipient, function () use ($recipient): void {
                $recipient->forceFill([
                    'send_lease_token' => null,
                    'send_lease_expires_at' => null,
                ])->save();
            });
        }
    }

    private function deferForSenderIdentity(SendAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt): void {
            $attempt = SendAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $recipient = CampaignRecipient::query()->whereKey($attempt->campaign_recipient_id)->lockForUpdate()->first();

            $attempt->forceFill([
                'status' => SendAttemptStatus::Deferred,
                'deferred_reason' => 'sender_identity_unavailable',
                'error_code' => 'sender_identity_unavailable',
                'safe_error_message' => SenderIdentityUnavailable::USER_MESSAGE,
                'provider_phase' => null,
            ])->save();

            if ($recipient) {
                $remaining = $recipient->next_send_at
                    ? max(0, now()->diffInSeconds($recipient->next_send_at, false))
                    : $recipient->remaining_delay_seconds;

                $this->governedInputs->runRecipientExecutionState($recipient, function () use ($recipient, $remaining): void {
                    $recipient->forceFill([
                        'blocked_from_status' => RecipientStatus::Active->value,
                        'blocked_at' => now(),
                        'remaining_delay_seconds' => $remaining,
                        'status' => RecipientStatus::BlockedMailbox,
                        'send_lease_token' => null,
                        'send_lease_expires_at' => null,
                    ])->save();
                });
            }
        });
    }

    private function withTrackingPixel(?string $html, ?string $token): string
    {
        $html ??= '';

        if (! $token) {
            return $html;
        }

        $url = url('/t/'.$token.'.gif');

        return $html.'<img src="'.e($url).'" width="1" height="1" alt="" />';
    }

    private function workspaceConsistent(
        SendAttempt $attempt,
        Mailbox $mailbox,
        ProviderConnection $connection,
        Message $message,
        CampaignRecipient $recipient,
        ?Message $parent,
        ?Thread $parentThread,
    ): bool {
        $workspaceId = (int) $attempt->getAttribute('workspace_id');
        $campaign = Campaign::query()->whereKey((int) $recipient->getAttribute('campaign_id'))->first();

        if ($campaign === null) {
            return false;
        }

        $thread = Thread::query()->whereKey((int) $message->getAttribute('thread_id'))->first();

        if ($workspaceId <= 0
            || (int) $mailbox->getAttribute('workspace_id') !== $workspaceId
            || (int) $connection->getAttribute('workspace_id') !== $workspaceId
            || (int) $message->getAttribute('workspace_id') !== $workspaceId
            || (int) $recipient->getAttribute('workspace_id') !== $workspaceId
            || (int) $campaign->getAttribute('workspace_id') !== $workspaceId
            || $thread === null
            || (int) $thread->getAttribute('workspace_id') !== $workspaceId
            || (int) $thread->getAttribute('mailbox_id') !== (int) $mailbox->id
            || (int) $message->getAttribute('thread_id') !== (int) $thread->id
            || (int) $attempt->getAttribute('campaign_recipient_id') !== (int) $recipient->id
            || (int) $attempt->getAttribute('mailbox_id') !== (int) $mailbox->id
            || (int) $attempt->getAttribute('message_id') !== (int) $message->id
            || (int) $message->getAttribute('mailbox_id') !== (int) $mailbox->id
            || (int) $message->getAttribute('campaign_recipient_id') !== (int) $recipient->id
            || (int) $recipient->getAttribute('campaign_id') !== (int) $campaign->getAttribute('id')
            || (int) $recipient->getAttribute('mailbox_id') !== (int) $mailbox->id) {
            return false;
        }

        if ($message->getAttribute('reply_to_message_id') !== null) {
            if ($parent === null || $parentThread === null
                || (int) $parent->getAttribute('workspace_id') !== $workspaceId
                || (int) $parentThread->getAttribute('workspace_id') !== $workspaceId
                || (int) $parent->getAttribute('mailbox_id') !== (int) $mailbox->id
                || (int) $parent->getAttribute('thread_id') !== (int) $parentThread->id
                || (int) $parentThread->id !== (int) $thread->id
                || (int) $parentThread->getAttribute('mailbox_id') !== (int) $mailbox->id
                || ($parent->getAttribute('campaign_recipient_id') !== null
                    && (int) $parent->getAttribute('campaign_recipient_id') !== (int) $recipient->id)) {
                return false;
            }
        }

        return true;
    }

    private function failForWorkspaceMismatch(SendAttempt $attempt, ?CampaignRecipient $recipient = null): void
    {
        $attempt->forceFill([
            'status' => SendAttemptStatus::Failed,
            'error_code' => 'workspace_mismatch',
            'safe_error_message' => 'Send blocked because its records belong to different workspaces.',
            'provider_phase' => null,
        ])->save();

        if ($recipient !== null
            && (int) $recipient->getAttribute('workspace_id') > 0
            && (int) $recipient->getAttribute('workspace_id') === (int) $attempt->getAttribute('workspace_id')) {
            CampaignRecipient::query()
                ->whereKey($recipient->getKey())
                ->where('workspace_id', $attempt->getAttribute('workspace_id'))
                ->update([
                    'send_lease_token' => null,
                    'send_lease_expires_at' => null,
                ]);
        }
    }

    private function releaseExperimentLease(
        SendAttempt $attempt,
        CampaignRecipient $recipient,
        bool $defer = false,
        ?string $deferredReason = null,
    ): void {
        DB::transaction(function () use ($attempt, $recipient, $defer, $deferredReason): void {
            $lockedAttempt = SendAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->first();
            if ($lockedAttempt === null || $lockedAttempt->getAttribute('status') !== SendAttemptStatus::LeaseAcquired) {
                return;
            }

            $lockedAttempt->forceFill([
                'status' => $defer ? SendAttemptStatus::Deferred : SendAttemptStatus::Queued,
                'provider_phase' => null,
                'deferred_reason' => $defer ? $deferredReason : null,
            ])->save();

            CampaignRecipient::query()
                ->whereKey($recipient->getKey())
                ->where('send_lease_token', $recipient->getAttribute('send_lease_token'))
                ->update([
                    'send_lease_token' => null,
                    'send_lease_expires_at' => null,
                ]);
        });
    }
}
