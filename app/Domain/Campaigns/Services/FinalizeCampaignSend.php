<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Support\SendWindowCalculator;
use App\Domain\Mail\DTOs\RemoteMessage;
use App\Domain\Mail\DTOs\SendResult;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Jobs\Objectives\RecordObjectiveExposure;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ExecutionPlanActionCompilation;
use App\Models\Message;
use App\Models\SendAttempt;
use App\Models\SequenceStep;
use App\Models\Thread;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Atomically commits a provider-accepted campaign send.
 *
 * The provider call happens before this service is invoked. Once the provider
 * has confirmed the message, all local projections must move together so a
 * lost response or a retried reconciliation cannot leave a recipient leased
 * at the old sequence step.
 */
class FinalizeCampaignSend
{
    public function __construct(
        private SendWindowCalculator $windows,
        private GovernedCampaignInputGuard $governedInputs,
    ) {}

    public function handle(
        SendAttempt $attempt,
        SendResult $result,
        ?RemoteMessage $remote = null,
        ?string $bodyHtml = null,
    ): void {
        DB::transaction(function () use ($attempt, $result, $remote, $bodyHtml): void {
            $lockedAttempt = SendAttempt::query()
                ->whereKey($attempt->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedAttempt === null) {
                return;
            }

            if (! in_array($lockedAttempt->getAttribute('status'), [
                SendAttemptStatus::Sending,
                SendAttemptStatus::ProviderAccepted,
                SendAttemptStatus::Unknown,
                SendAttemptStatus::ManualReview,
                SendAttemptStatus::Sent,
            ], true)) {
                return;
            }

            $messageId = $lockedAttempt->getAttribute('message_id');
            $message = is_numeric($messageId)
                ? Message::query()->whereKey((int) $messageId)->lockForUpdate()->first()
                : null;

            if ($message === null) {
                return;
            }

            $threadId = $message->getAttribute('thread_id');
            $thread = is_numeric($threadId)
                ? Thread::query()->whereKey((int) $threadId)->lockForUpdate()->first()
                : null;

            $recipientId = $lockedAttempt->getAttribute('campaign_recipient_id')
                ?? $message->getAttribute('campaign_recipient_id');
            $recipient = is_numeric($recipientId)
                ? CampaignRecipient::query()->whereKey((int) $recipientId)->lockForUpdate()->first()
                : null;
            $campaign = $recipient === null
                ? null
                : Campaign::query()->whereKey((int) $recipient->getAttribute('campaign_id'))->lockForUpdate()->first();

            if (! $this->workspaceConsistent($lockedAttempt, $message, $thread, $recipient, $campaign)) {
                return;
            }

            $sentAt = $lockedAttempt->getAttribute('sent_at');

            if (! $sentAt instanceof DateTimeInterface) {
                $sentAt = $message->getAttribute('sent_at');
            }

            if (! $sentAt instanceof DateTimeInterface) {
                $sentAt = now();
            }

            $acceptedAt = $lockedAttempt->getAttribute('provider_accepted_at');
            $remoteMessageId = $this->firstNonEmpty(
                $message->getAttribute('remote_message_id'),
                $result->remoteMessageId,
                $remote?->remoteMessageId,
            );
            $internetMessageId = $this->firstNonEmpty(
                $message->getAttribute('internet_message_id'),
                $result->internetMessageId,
                $remote?->internetMessageId,
            );
            $remoteThreadId = $this->firstNonEmpty(
                $thread?->getAttribute('remote_thread_id'),
                $result->remoteThreadId,
                $remote?->remoteThreadId,
            );
            $wasAlreadySent = $lockedAttempt->getAttribute('status') === SendAttemptStatus::Sent;
            $submissionOnly = ! $wasAlreadySent && $result->isProviderSubmissionOnly();
            $finalAttemptStatus = $submissionOnly
                ? SendAttemptStatus::ProviderAccepted
                : SendAttemptStatus::Sent;
            $finalMessageStatus = $submissionOnly
                ? MessageStatus::ProviderAccepted
                : MessageStatus::Sent;

            $lockedAttempt->forceFill([
                'status' => $finalAttemptStatus,
                'due_at' => null,
                'deferred_reason' => null,
                'error_code' => null,
                'safe_error_message' => null,
                'provider_accepted_at' => $acceptedAt ?? $sentAt,
                'sent_at' => $submissionOnly ? null : $sentAt,
                'provider_phase' => $wasAlreadySent
                    ? ($lockedAttempt->getAttribute('provider_phase') ?? $result->phase)
                    : $result->phase,
                'provider_request_id' => $result->providerRequestId
                    ?? $lockedAttempt->getAttribute('provider_request_id'),
                'provider_draft_id' => $result->providerDraftId
                    ?? $lockedAttempt->getAttribute('provider_draft_id'),
                'provider_marker' => is_string($result->metadata['client_message_marker'] ?? null)
                    ? $result->metadata['client_message_marker']
                    : $lockedAttempt->getAttribute('provider_marker'),
            ])->save();

            $messageAttributes = array_filter([
                'status' => $finalMessageStatus,
                'sent_at' => $submissionOnly ? null : ($message->getAttribute('sent_at') ?? $sentAt),
                'body_html_sanitized' => $bodyHtml,
            ], static fn (mixed $value): bool => $value !== null);

            if ($remoteMessageId !== null) {
                $messageAttributes['remote_message_id'] = $remoteMessageId;
            }

            if ($internetMessageId !== null) {
                $messageAttributes['internet_message_id'] = $internetMessageId;
            }

            $message->forceFill($messageAttributes)->save();

            $this->projectGovernedActionState($lockedAttempt, $submissionOnly);
            $this->scheduleGovernedResultProjection($lockedAttempt);

            if ($thread !== null) {
                $threadAttributes = ['last_message_at' => $sentAt];

                if ($remoteThreadId !== null) {
                    $threadAttributes['remote_thread_id'] = $remoteThreadId;
                }

                $thread->forceFill($threadAttributes)->save();
            }

            if ($recipient !== null && $campaign !== null) {
                $this->governedInputs->runCampaignSendFinalization(
                    $campaign,
                    $recipient,
                    fn () => $this->advance($recipient, $campaign, $lockedAttempt, $sentAt),
                );
            }
        });
    }

    private function scheduleGovernedResultProjection(SendAttempt $attempt): void
    {
        $workspaceId = (int) $attempt->getAttribute('workspace_id');
        $attemptId = (int) $attempt->getKey();
        DB::afterCommit(static function () use ($workspaceId, $attemptId): void {
            try {
                RecordObjectiveExposure::dispatch($workspaceId, $attemptId);
            } catch (Throwable $exception) {
                // Mail acceptance is already durable. The bounded Objective
                // reconciler repairs this projection without another send.
                Log::warning('Objective exposure projection dispatch failed.', [
                    'workspace_id' => $workspaceId,
                    'send_attempt_id' => $attemptId,
                    'exception' => $exception::class,
                ]);
            }
        });
    }

    private function projectGovernedActionState(SendAttempt $attempt, bool $submissionOnly): void
    {
        $workspaceId = (int) $attempt->getAttribute('workspace_id');
        $links = ExecutionPlanActionCompilation::query()
            ->where('workspace_id', $workspaceId)
            ->where('campaign_recipient_id', $attempt->getAttribute('campaign_recipient_id'))
            ->where('sequence_step_id', $attempt->getAttribute('sequence_step_id'))
            ->get();
        if ($links->count() !== 1) {
            return;
        }

        DB::table('execution_plan_actions')
            ->where('workspace_id', $workspaceId)
            ->where('id', $links->first()->execution_plan_action_id)
            ->update([
                'state' => $submissionOnly
                    ? ExecutionPlanActionState::ProviderAccepted->value
                    : ExecutionPlanActionState::Sent->value,
                'updated_at' => now(),
            ]);
    }

    private function advance(
        CampaignRecipient $recipient,
        Campaign $campaign,
        SendAttempt $attempt,
        DateTimeInterface $sentAt,
    ): void {
        $recipientAttributes = [
            'first_sent_at' => $recipient->getAttribute('first_sent_at') ?? $sentAt,
            'last_sent_at' => $this->latestTimestamp($recipient->getAttribute('last_sent_at'), $sentAt),
            'send_lease_token' => null,
            'send_lease_expires_at' => null,
        ];

        $attemptStepId = $attempt->getAttribute('sequence_step_id');
        $attemptStep = is_numeric($attemptStepId)
            ? SequenceStep::query()->whereKey((int) $attemptStepId)->first()
            : null;
        $attemptPosition = $attemptStep?->getAttribute('position') ?? 0;
        $next = SequenceStep::query()
            ->where('sequence_version_id', $recipient->getAttribute('sequence_version_id'))
            ->whereNull('deleted_at')
            ->where('position', '>', $attemptPosition)
            ->orderBy('position')
            ->first();
        $currentNextId = $recipient->getAttribute('next_sequence_step_id');
        $currentNext = is_numeric($currentNextId)
            ? SequenceStep::query()->whereKey((int) $currentNextId)->first()
            : null;

        if ($next === null) {
            $recipientAttributes['status'] = RecipientStatus::Completed;
            $recipientAttributes['next_sequence_step_id'] = null;
            $recipientAttributes['next_send_at'] = null;
            $recipient->forceFill($recipientAttributes)->save();

            $open = $campaign->recipients()
                ->whereNotIn('status', array_map(
                    fn (RecipientStatus $status): string => $status->value,
                    [
                        RecipientStatus::Completed,
                        RecipientStatus::Replied,
                        RecipientStatus::Interested,
                        RecipientStatus::NotNow,
                        RecipientStatus::WrongPerson,
                        RecipientStatus::Bounced,
                        RecipientStatus::Unsubscribed,
                        RecipientStatus::Removed,
                    ],
                ))
                ->exists();

            if (! $open) {
                DB::afterCommit(fn () => app(CompleteFinishedCampaign::class)->handle(
                    (int) $campaign->getAttribute('workspace_id'), (int) $campaign->id,
                ));
            }

            return;
        }

        // If a previous invocation already moved this recipient beyond this
        // attempt, do not move it backward or recompute next_send_at. This is
        // what makes a replay after a partially persisted response harmless.
        if ($currentNext !== null && (int) $currentNext->getAttribute('position') > (int) $attemptPosition) {
            $recipient->forceFill($recipientAttributes)->save();

            return;
        }

        $recipientAttributes['next_sequence_step_id'] = $next->getKey();

        if ((int) $currentNext?->getKey() !== (int) $next->getKey() || $recipient->getAttribute('next_send_at') === null) {
            $due = $this->windows->nextAllowed(
                CarbonImmutable::instance($sentAt)->addMinutes((int) $next->getAttribute('delay_minutes')),
                (string) $campaign->getAttribute('timezone'),
                (string) $campaign->getAttribute('send_window_start'),
                (string) $campaign->getAttribute('send_window_end'),
                (bool) $campaign->getAttribute('business_days_only'),
            );
            $recipientAttributes['next_send_at'] = $due;
        }

        $recipient->forceFill($recipientAttributes)->save();
    }

    private function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function latestTimestamp(mixed $existing, DateTimeInterface $candidate): DateTimeInterface
    {
        if (! $existing instanceof DateTimeInterface || $candidate->getTimestamp() > $existing->getTimestamp()) {
            return $candidate;
        }

        return $existing;
    }

    private function workspaceConsistent(
        SendAttempt $attempt,
        Message $message,
        ?Thread $thread,
        ?CampaignRecipient $recipient,
        ?Campaign $campaign,
    ): bool {
        $workspaceId = (int) $attempt->getAttribute('workspace_id');

        if ($workspaceId <= 0
            || (int) $message->getAttribute('workspace_id') !== $workspaceId
            || $thread === null
            || (int) $thread->getAttribute('workspace_id') !== $workspaceId
            || (int) $message->getAttribute('thread_id') !== (int) $thread->getKey()
            || (int) $attempt->getAttribute('mailbox_id') !== (int) $message->getAttribute('mailbox_id')
            || (int) $message->getAttribute('mailbox_id') !== (int) $thread->getAttribute('mailbox_id')) {
            return false;
        }

        if ($recipient === null) {
            return $attempt->getAttribute('campaign_recipient_id') === null
                && $message->getAttribute('campaign_recipient_id') === null;
        }

        return $campaign !== null
            && (int) $recipient->getAttribute('workspace_id') === $workspaceId
            && (int) $campaign->getAttribute('workspace_id') === $workspaceId
            && (int) $attempt->getAttribute('campaign_recipient_id') === (int) $recipient->getKey()
            && (int) $message->getAttribute('campaign_recipient_id') === (int) $recipient->getKey()
            && (int) $recipient->getAttribute('campaign_id') === (int) $campaign->getKey()
            && (int) $recipient->getAttribute('mailbox_id') === (int) $message->getAttribute('mailbox_id');
    }
}
