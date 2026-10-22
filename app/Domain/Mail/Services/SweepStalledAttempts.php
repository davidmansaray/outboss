<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Objectives\Enums\ExecutionActionEventType;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Domain\Objectives\Services\RecordExecutionActionEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\OutboxEvent;
use App\Models\SendAttempt;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class SweepStalledAttempts
{
    public function __construct(private readonly RecordExecutionActionEvent $actionEvents) {}

    public function handle(): int
    {
        $repaired = 0;

        SendAttempt::query()
            ->whereIn('status', [SendAttemptStatus::Scheduled, SendAttemptStatus::Queued])
            ->where('created_at', '<', now()->subMinutes(2))
            ->each(function (SendAttempt $candidate) use (&$repaired): void {
                if ($this->repairQueuedAttempt($candidate->getKey())) {
                    $repaired++;
                }
            });

        SendAttempt::query()
            ->where('status', SendAttemptStatus::Sending)
            ->each(function (SendAttempt $candidate) use (&$repaired): void {
                if ($this->recoverStalledSending($candidate->getKey())) {
                    $repaired++;
                }
            });

        SendAttempt::query()
            ->where('status', SendAttemptStatus::LeaseAcquired)
            ->each(function (SendAttempt $candidate) use (&$repaired): void {
                if ($this->expireLease($candidate->getKey())) {
                    $repaired++;
                }
            });

        return $repaired;
    }

    private function repairQueuedAttempt(int|string $attemptId): bool
    {
        return (bool) DB::transaction(function () use ($attemptId): bool {
            $attempt = SendAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();
            $createdAt = $attempt?->getAttribute('created_at');

            if ($attempt === null
                || ! in_array($attempt->getAttribute('status'), [
                    SendAttemptStatus::Scheduled,
                    SendAttemptStatus::Queued,
                ], true)
                || ! $this->isBefore($createdAt, now()->subMinutes(2))) {
                return false;
            }

            $attempt->load(['message', 'mailbox', 'recipient.campaign']);

            if (! $this->workspaceConsistent($attempt)) {
                $attempt->forceFill([
                    'status' => SendAttemptStatus::Failed,
                    'error_code' => 'workspace_mismatch',
                    'safe_error_message' => 'Send blocked because its records belong to different workspaces.',
                ])->save();

                return true;
            }

            $exists = OutboxEvent::query()
                ->where('aggregate_type', 'send_attempt')
                ->where('aggregate_id', $attempt->id)
                ->whereNull('published_at')
                ->exists();

            if ($exists) {
                return false;
            }

            OutboxEvent::query()->create([
                'type' => 'send_attempt.created',
                'aggregate_type' => 'send_attempt',
                'aggregate_id' => $attempt->id,
                'payload' => ['send_attempt_id' => $attempt->id],
                'available_at' => now(),
            ]);

            return true;
        });
    }

    /**
     * A worker can die after the provider-facing phase is persisted but before
     * the provider response is written.  That state is ambiguous: retrying it
     * as a fresh send could deliver a duplicate.  Preserve every provider
     * correlation/timing field and hand the attempt to the normal unknown-send
     * reconciliation loop instead.
     */
    private function recoverStalledSending(int|string $attemptId): bool
    {
        $cutoff = now()->subMinutes(6);

        return (bool) DB::transaction(function () use ($attemptId, $cutoff): bool {
            $attempt = SendAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();

            if ($attempt === null
                || $attempt->getAttribute('status') !== SendAttemptStatus::Sending
                || ! $this->isBefore($this->attemptProgressAt($attempt), $cutoff)) {
                return false;
            }

            $attributes = [
                'status' => SendAttemptStatus::Unknown,
                'due_at' => now(),
                'deferred_reason' => null,
                'error_code' => 'provider_result_unknown',
                'safe_error_message' => 'The worker stopped during provider submission. Reconcile the send before retrying.',
            ];

            // These counters are added by the send-evidence migration. Keep
            // this service compatible with old release databases while making
            // a crash visible once that column is present.
            if (array_key_exists('provider_unknown_count', $attempt->getAttributes())) {
                $attributes['provider_unknown_count'] = (int) $attempt->getAttribute('provider_unknown_count') + 1;
            }

            // Deliberately do not clear provider_phase, provider_marker,
            // provider_request_id, provider_attempted_at, or the recipient's
            // lease. Finalization/review owns releasing that lease.
            $attempt->forceFill($attributes)->save();
            $event = $this->actionEvents->forAttempt(
                $attempt,
                ExecutionActionEventType::Uncertain,
                'provider_result_unknown',
            );
            if ($event !== null) {
                DB::table('execution_plan_actions')->where('id', $event->execution_plan_action_id)->update([
                    'state' => ExecutionPlanActionState::Uncertain->value,
                    'updated_at' => now(),
                ]);
            }

            return true;
        });
    }

    private function expireLease(int|string $attemptId): bool
    {
        $cutoff = now()->subMinutes(6);

        return (bool) DB::transaction(function () use ($attemptId, $cutoff): bool {
            $attempt = SendAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();
            $leaseAcquiredAt = $attempt?->getAttribute('lease_acquired_at');

            if ($attempt === null
                || $attempt->getAttribute('status') !== SendAttemptStatus::LeaseAcquired
                || ! $this->isBefore($leaseAcquiredAt, $cutoff)) {
                return false;
            }

            $recipientId = $attempt->getAttribute('campaign_recipient_id');
            $recipient = is_numeric($recipientId)
                ? CampaignRecipient::query()->whereKey((int) $recipientId)->lockForUpdate()->first()
                : null;
            $leaseToken = $recipient?->getAttribute('send_lease_token');

            $attempt->forceFill([
                'status' => SendAttemptStatus::Deferred,
                'deferred_reason' => 'expired_lease',
            ])->save();

            if ($recipient !== null && is_string($leaseToken) && $leaseToken !== '') {
                CampaignRecipient::query()
                    ->whereKey($recipient->getKey())
                    ->where('send_lease_token', $leaseToken)
                    ->update([
                        'send_lease_token' => null,
                        'send_lease_expires_at' => null,
                    ]);
            }

            return true;
        });
    }

    private function attemptProgressAt(SendAttempt $attempt): ?DateTimeInterface
    {
        $attemptedAt = $attempt->getAttribute('provider_attempted_at');

        if ($attemptedAt instanceof DateTimeInterface) {
            return $attemptedAt;
        }

        $updatedAt = $attempt->getAttribute('updated_at');

        return $updatedAt instanceof DateTimeInterface ? $updatedAt : null;
    }

    private function isBefore(mixed $value, DateTimeInterface $cutoff): bool
    {
        return $value instanceof DateTimeInterface
            && $value->getTimestamp() < $cutoff->getTimestamp();
    }

    private function workspaceConsistent(SendAttempt $attempt): bool
    {
        $workspaceId = (int) $attempt->getAttribute('workspace_id');
        $mailbox = $attempt->mailbox;
        $message = $attempt->message;
        $recipient = $attempt->recipient;

        return $workspaceId > 0
            && $mailbox !== null
            && $message !== null
            && (int) $mailbox->getAttribute('workspace_id') === $workspaceId
            && (int) $message->getAttribute('workspace_id') === $workspaceId
            && (int) $attempt->getAttribute('mailbox_id') === (int) $mailbox->getAttribute('id')
            && (int) $attempt->getAttribute('message_id') === (int) $message->getAttribute('id')
            && (int) $message->getAttribute('mailbox_id') === (int) $mailbox->getAttribute('id')
            && ($recipient === null || (
                (int) $recipient->getAttribute('workspace_id') === $workspaceId
                && (int) Campaign::query()->whereKey((int) $recipient->getAttribute('campaign_id'))->value('workspace_id') === $workspaceId
                && (int) $recipient->getAttribute('mailbox_id') === (int) $mailbox->getAttribute('id')
            ));
    }
}
