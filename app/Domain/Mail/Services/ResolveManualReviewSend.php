<?php

namespace App\Domain\Mail\Services;

use App\Domain\Campaigns\Services\FinalizeCampaignSend;
use App\Domain\Mail\DTOs\SendResult;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\SendResolution;
use App\Domain\Objectives\Enums\ExecutionActionEventType;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Domain\Objectives\Services\RecordExecutionActionEvent;
use App\Models\CampaignRecipient;
use App\Models\Message;
use App\Models\OutboxEvent;
use App\Models\SendAttempt;
use App\Models\SendAttemptResolution;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResolveManualReviewSend
{
    public function __construct(
        private FinalizeCampaignSend $finalizer,
        private RecordExecutionActionEvent $actionEvents,
    ) {}

    public function handle(SendAttempt $attempt, SendResolution $resolution, string $operator, string $evidence): SendAttemptResolution
    {
        return DB::transaction(function () use ($attempt, $resolution, $operator, $evidence): SendAttemptResolution {
            $lockedAttempt = SendAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();
            $existing = SendAttemptResolution::query()
                ->where('send_attempt_id', $lockedAttempt->getKey())
                ->where('review_cycle', $lockedAttempt->getAttribute('manual_review_cycle'))
                ->first();

            if ($lockedAttempt->getAttribute('status') !== SendAttemptStatus::ManualReview) {
                if ($existing !== null
                    && $existing->getAttribute('resolution') === $resolution
                    && $this->resolutionMatchesTerminalState($lockedAttempt, $resolution)) {
                    // A retried operator command must not create another row
                    // or re-run a provider-facing operation for this cycle.
                    return $existing;
                }

                throw new RuntimeException('attempt_not_in_review');
            }

            if ($existing !== null) {
                if ($existing->getAttribute('resolution') !== $resolution) {
                    throw new RuntimeException('review_cycle_already_resolved');
                }

                return $existing;
            }

            $row = SendAttemptResolution::query()->create([
                'send_attempt_id' => $lockedAttempt->getKey(),
                'review_cycle' => $lockedAttempt->getAttribute('manual_review_cycle'),
                'resolution' => $resolution,
                'operator_identifier' => $operator,
                'safe_evidence' => $evidence,
            ]);

            if ($resolution === SendResolution::ConfirmedSent) {
                $this->restoreSafeLegacyMessageLink($lockedAttempt);
                $this->finalizer->handle(
                    $lockedAttempt,
                    $this->manualConfirmedSendResult($lockedAttempt),
                );

                if (SendAttempt::query()->whereKey($lockedAttempt->getKey())->first()?->getAttribute('status') !== SendAttemptStatus::Sent) {
                    throw new RuntimeException('send_finalize_failed');
                }
            } else {
                $this->markProvenNotSent($lockedAttempt);

                if (SendAttempt::query()->whereKey($lockedAttempt->getKey())->first()?->getAttribute('status') !== SendAttemptStatus::Deferred) {
                    throw new RuntimeException('send_retry_activation_failed');
                }
            }

            return $row;
        });
    }

    /**
     * Older campaign attempts can have a durable recipient link while their
     * locally queued message predates the matching message column. Restore
     * that link only when workspace and mailbox ownership agree; the shared
     * finalizer still performs its full consistency check afterwards.
     */
    private function restoreSafeLegacyMessageLink(SendAttempt $attempt): void
    {
        $messageId = $attempt->getAttribute('message_id');
        $recipientId = $attempt->getAttribute('campaign_recipient_id');
        if (! is_numeric($messageId) || ! is_numeric($recipientId)) {
            return;
        }

        $message = Message::query()->whereKey((int) $messageId)->lockForUpdate()->first();
        if ($message === null || $message->getAttribute('campaign_recipient_id') !== null) {
            return;
        }

        $recipient = CampaignRecipient::query()->whereKey((int) $recipientId)->lockForUpdate()->first();
        if ($recipient === null
            || (int) $attempt->getAttribute('workspace_id') !== (int) $message->getAttribute('workspace_id')
            || (int) $attempt->getAttribute('workspace_id') !== (int) $recipient->getAttribute('workspace_id')
            || (int) $attempt->getAttribute('mailbox_id') !== (int) $message->getAttribute('mailbox_id')
            || (int) $attempt->getAttribute('mailbox_id') !== (int) $recipient->getAttribute('mailbox_id')) {
            return;
        }

        $message->forceFill(['campaign_recipient_id' => $recipient->getKey()])->save();
    }

    private function manualConfirmedSendResult(SendAttempt $attempt): SendResult
    {
        $message = Message::query()->whereKey($attempt->getAttribute('message_id'))->first();

        return new SendResult(
            // Manual evidence may not include a provider message ID. An empty
            // ID is accepted by the finalizer, which keeps any existing ID and
            // still commits the local sent/recipient projections atomically.
            remoteMessageId: (string) ($message?->getAttribute('remote_message_id') ?? ''),
            remoteThreadId: null,
            internetMessageId: $message?->getAttribute('internet_message_id'),
            providerDraftId: $attempt->getAttribute('provider_draft_id'),
            providerRequestId: $attempt->getAttribute('provider_request_id'),
            phase: 'manual_confirmed_sent',
        );
    }

    private function markProvenNotSent(SendAttempt $attempt): void
    {
        $recipientId = $attempt->getAttribute('campaign_recipient_id');
        $recipient = is_numeric($recipientId)
            ? CampaignRecipient::query()->whereKey((int) $recipientId)->lockForUpdate()->first()
            : null;
        $leaseToken = $recipient?->getAttribute('send_lease_token');

        $attempt->forceFill([
            'status' => SendAttemptStatus::Deferred,
            'due_at' => null,
            'deferred_reason' => 'proven_not_sent',
            'provider_phase' => null,
            'provider_draft_id' => null,
            'error_code' => null,
            'safe_error_message' => null,
        ])->save();
        $event = $this->actionEvents->forAttempt(
            $attempt,
            ExecutionActionEventType::Failed,
            'proven_not_sent',
        );
        if ($event !== null) {
            DB::table('execution_plan_actions')->where('id', $event->execution_plan_action_id)->update([
                'state' => ExecutionPlanActionState::Failed->value,
                'updated_at' => now(),
            ]);
        }

        if ($recipient !== null && is_string($leaseToken) && $leaseToken !== '') {
            CampaignRecipient::query()
                ->whereKey($recipient->getKey())
                ->where('send_lease_token', $leaseToken)
                ->update([
                    'send_lease_token' => null,
                    'send_lease_expires_at' => null,
                ]);
        }

        OutboxEvent::query()->create([
            'type' => 'send_attempt.created',
            'aggregate_type' => 'send_attempt',
            'aggregate_id' => $attempt->getKey(),
            'payload' => ['send_attempt_id' => $attempt->getKey()],
            'available_at' => now(),
        ]);
    }

    private function resolutionMatchesTerminalState(SendAttempt $attempt, SendResolution $resolution): bool
    {
        return ($resolution === SendResolution::ConfirmedSent
                && $attempt->getAttribute('status') === SendAttemptStatus::Sent)
            || ($resolution === SendResolution::ProvenNotSent
                && $attempt->getAttribute('status') === SendAttemptStatus::Deferred
                && $attempt->getAttribute('deferred_reason') === 'proven_not_sent');
    }
}
