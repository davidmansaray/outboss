<?php

namespace App\Domain\Mail\Services;

use App\Domain\Campaigns\Services\FinalizeCampaignSend;
use App\Domain\Mail\DTOs\SendReconciliationResult;
use App\Domain\Mail\DTOs\SendResult;
use App\Domain\Mail\DTOs\UnknownSendReference;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Providers\ProviderRegistry;
use App\Domain\Objectives\Enums\ExecutionActionEventType;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Domain\Objectives\Services\RecordExecutionActionEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\OutboxEvent;
use App\Models\ProviderConnection;
use App\Models\SendAttempt;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReconcileUnknownSend
{
    public function __construct(
        private ProviderRegistry $registry,
        private EnsureConnectionUsable $connections,
        private FinalizeCampaignSend $finalizer,
        private RecordExecutionActionEvent $actionEvents,
    ) {}

    public function handle(?int $attemptId = null): int
    {
        $now = now();
        $query = SendAttempt::query()
            ->where('status', SendAttemptStatus::Unknown)
            ->where(function ($query) use ($now): void {
                $query->whereNull('due_at')->orWhere('due_at', '<=', $now);
            })
            ->with(['message', 'mailbox', 'recipient.campaign']);
        if ($attemptId) {
            $query->whereKey($attemptId);
        }

        $moved = 0;

        $query->each(function (SendAttempt $attempt) use (&$moved): void {
            if (! $this->workspaceConsistent($attempt)) {
                DB::transaction(function () use ($attempt): void {
                    $lockedAttempt = SendAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->first();

                    if ($lockedAttempt?->getAttribute('status') !== SendAttemptStatus::Unknown) {
                        return;
                    }

                    $lockedAttempt->forceFill([
                        'status' => SendAttemptStatus::Failed,
                        'error_code' => 'workspace_mismatch',
                        'safe_error_message' => 'Send blocked because its records belong to different workspaces.',
                    ])->save();
                });
                $moved++;

                return;
            }

            $mailbox = $attempt->mailbox;
            $message = $attempt->message;
            $connection = $mailbox instanceof Mailbox ? $mailbox->connection : null;

            if (! $mailbox instanceof Mailbox
                || ! $message instanceof Message
                || ! $connection instanceof ProviderConnection) {
                $this->moveToManualReview($attempt, 'mailbox_connection_missing');
                $moved++;

                return;
            }

            // The sweep updates updated_at while recovering a crashed worker;
            // use the durable provider-attempt timestamp when available so a
            // consistency window is measured from submission, not recovery.
            $providerAttemptedAt = $attempt->getAttribute('provider_attempted_at');
            $acceptedAt = $attempt->getAttribute('provider_accepted_at');
            $leaseAcquiredAt = $attempt->getAttribute('lease_acquired_at');
            $createdAt = $attempt->getAttribute('created_at');
            $updatedAt = $attempt->getAttribute('updated_at');
            $submittedAt = $providerAttemptedAt instanceof DateTimeInterface
                ? $providerAttemptedAt
                : ($acceptedAt instanceof DateTimeInterface
                    ? $acceptedAt
                    : ($leaseAcquiredAt instanceof DateTimeInterface
                        ? $leaseAcquiredAt
                        : ($createdAt instanceof DateTimeInterface
                            ? $createdAt
                            : ($updatedAt instanceof DateTimeInterface ? $updatedAt : null))));

            try {
                $provider = $connection->getAttribute('provider');
                $provider = $provider instanceof \BackedEnum ? $provider->value : $provider;
                $provider = (string) $provider;

                $this->connections->handle($connection);
                $result = $this->registry->transport($provider)
                    ->reconcileUnknownSend($mailbox, new UnknownSendReference(
                        clientMessageMarker: $attempt->getAttribute('provider_marker') ?: $message->getAttribute('client_message_marker'),
                        internetMessageId: $message->getAttribute('internet_message_id'),
                        subject: $message->getAttribute('subject'),
                        submittedAt: $submittedAt,
                        providerRequestId: $attempt->getAttribute('provider_request_id'),
                        submissionState: $this->submissionState($attempt, $provider),
                    ));
            } catch (Throwable) {
                $pending = $this->pendingForAttempt($submittedAt);

                if ($pending !== null) {
                    $this->keepPending($attempt, $pending, $submittedAt);
                    $moved++;

                    return;
                }

                $this->moveToManualReview($attempt, 'provider_reconciliation_unavailable');
                $moved++;

                return;
            }

            if ($result->isConfirmedSent()) {
                $this->markConfirmedSent($attempt, $result);
            } elseif ($result->isPending()) {
                $this->keepPending($attempt, $result, $submittedAt);
            } elseif ($result->requiresManualReview()) {
                $this->moveToManualReview(
                    $attempt,
                    $result->safeErrorCode ?? 'provider_reconciliation_ambiguous',
                    $result->safeErrorMessage ?? 'Ambiguous provider result; confirm before retrying.',
                );
            } elseif ($result->isConfirmedNotSent()) {
                $this->markConfirmedNotSent($attempt);
            } else {
                // Only an explicit provider non-acceptance can make an
                // ambiguous attempt retryable. Unknown result values fail
                // closed into operator review.
                $this->moveToManualReview(
                    $attempt,
                    'provider_reconciliation_unknown_result',
                    'The provider returned an unknown reconciliation result; confirm before retrying.',
                );
            }

            $moved++;
        });

        return $moved;
    }

    private function moveToManualReview(
        SendAttempt $attempt,
        string $code,
        string $message = 'The provider result could not be reconciled automatically; confirm before retrying.',
    ): void {
        DB::transaction(function () use ($attempt, $code, $message): void {
            $lockedAttempt = SendAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->first();

            if ($lockedAttempt?->getAttribute('status') !== SendAttemptStatus::Unknown) {
                return;
            }

            $lockedAttempt->forceFill([
                'status' => SendAttemptStatus::ManualReview,
                'manual_review_cycle' => (int) $lockedAttempt->getAttribute('manual_review_cycle') + 1,
                'provider_manual_review_count' => (int) $lockedAttempt->getAttribute('provider_manual_review_count') + 1,
                'due_at' => null,
                'deferred_reason' => null,
                'error_code' => $code,
                'safe_error_message' => $message,
            ])->save();
        });
    }

    /**
     * A proven non-acceptance is the only reconciliation result that may make
     * an ambiguous attempt retryable. Release the old recipient lease and
     * record the retry event in one transaction so a scheduler cannot race a
     * still-leased attempt into a duplicate send.
     */
    private function markConfirmedNotSent(SendAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt): void {
            $lockedAttempt = SendAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->first();

            if ($lockedAttempt?->getAttribute('status') !== SendAttemptStatus::Unknown) {
                return;
            }

            $recipientId = $lockedAttempt->getAttribute('campaign_recipient_id');
            $recipient = is_numeric($recipientId)
                ? CampaignRecipient::query()->whereKey((int) $recipientId)->lockForUpdate()->first()
                : null;
            $leaseToken = $recipient?->getAttribute('send_lease_token');

            $lockedAttempt->forceFill([
                'status' => SendAttemptStatus::Deferred,
                'due_at' => null,
                'deferred_reason' => 'reconciled_not_sent',
                'provider_phase' => null,
                'provider_draft_id' => null,
                'error_code' => null,
                'safe_error_message' => null,
            ])->save();
            $event = $this->actionEvents->forAttempt(
                $lockedAttempt,
                ExecutionActionEventType::Failed,
                'reconciled_not_sent',
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
                'aggregate_id' => $lockedAttempt->getKey(),
                'payload' => ['send_attempt_id' => $lockedAttempt->getKey()],
                'available_at' => now(),
            ]);
        });
    }

    private function keepPending(
        SendAttempt $attempt,
        SendReconciliationResult $result,
        ?DateTimeInterface $submittedAt,
    ): void {
        $nextRetryAt = $this->nextPendingRetryAt($result, $submittedAt);

        if ($nextRetryAt === null) {
            $this->moveToManualReview(
                $attempt,
                $result->safeErrorCode ?? 'provider_reconciliation_deadline_elapsed',
                $result->safeErrorMessage ?? 'The provider result could not be reconciled automatically; confirm before retrying.',
            );

            return;
        }

        DB::transaction(function () use ($attempt, $nextRetryAt, $result): void {
            $lockedAttempt = SendAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->first();

            if ($lockedAttempt?->getAttribute('status') !== SendAttemptStatus::Unknown) {
                return;
            }

            $lockedAttempt->forceFill([
                'status' => SendAttemptStatus::Unknown,
                'due_at' => $nextRetryAt,
                'deferred_reason' => null,
                'error_code' => $result->safeErrorCode ?? 'provider_reconciliation_pending',
                'safe_error_message' => $result->safeErrorMessage
                    ?? 'The provider result is not visible yet; retry reconciliation before deciding how to proceed.',
            ])->save();
        });
    }

    private function nextPendingRetryAt(
        SendReconciliationResult $result,
        ?DateTimeInterface $submittedAt,
    ): ?CarbonImmutable {
        $now = CarbonImmutable::now();
        $metadata = $result->metadata;
        $deadline = $this->dateMetadata($metadata['consistency_deadline'] ?? null);

        if ($submittedAt instanceof DateTimeInterface) {
            $configuredDeadline = CarbonImmutable::instance($submittedAt)
                ->addSeconds($this->reconciliationConsistencyDelay());
            $deadline = $deadline === null || $deadline->greaterThan($configuredDeadline)
                ? $configuredDeadline
                : $deadline;
        }

        if ($deadline === null || ! $deadline->isFuture()) {
            return null;
        }

        $retryAt = $this->dateMetadata($metadata['retry_at'] ?? null);

        if ($retryAt === null) {
            $retryAfter = $metadata['retry_after_seconds'] ?? null;
            $retryAfter = is_numeric($retryAfter) ? (int) $retryAfter : $this->reconciliationRetryDelay();
            $retryAt = $now->addSeconds(max(1, min(300, $retryAfter)));
        }

        if ($retryAt->lessThanOrEqualTo($now)) {
            $retryAt = $now->addSecond();
        }

        if ($retryAt->greaterThan($deadline)) {
            $retryAt = $deadline;
        }

        return $retryAt->isFuture() ? $retryAt : null;
    }

    private function pendingForAttempt(?DateTimeInterface $submittedAt): ?SendReconciliationResult
    {
        if (! $submittedAt instanceof DateTimeInterface) {
            return null;
        }

        $delay = $this->reconciliationConsistencyDelay();
        $deadline = CarbonImmutable::instance($submittedAt)->addSeconds($delay);
        $now = CarbonImmutable::now();

        if (! $deadline->isFuture()) {
            return null;
        }

        $retryAt = $now->addSeconds($this->reconciliationRetryDelay());

        if ($retryAt->greaterThan($deadline)) {
            $retryAt = $deadline;
        }

        return SendReconciliationResult::pending(
            code: 'provider_reconciliation_pending',
            message: 'The provider result is not available yet; retry reconciliation before the consistency deadline.',
            metadata: [
                'consistency_delay_seconds' => $delay,
                'consistency_deadline' => $deadline->toIso8601String(),
                'retry_at' => $retryAt->toIso8601String(),
            ],
        );
    }

    private function dateMetadata(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function reconciliationConsistencyDelay(): int
    {
        $configured = config('outboss.managed_mail.reconciliation_consistency_delay_seconds', 60);

        return max(1, min(3600, is_numeric($configured) ? (int) $configured : 60));
    }

    private function reconciliationRetryDelay(): int
    {
        $configured = config('outboss.managed_mail.reconciliation_retry_seconds', 30);

        return max(1, min(300, is_numeric($configured) ? (int) $configured : 30));
    }

    private function submissionState(SendAttempt $attempt, string $provider): ?string
    {
        if ($provider !== MailProviderName::Stalwart->value) {
            return null;
        }

        $phase = (string) ($attempt->getAttribute('provider_phase') ?? '');
        $errorCode = (string) ($attempt->getAttribute('error_code') ?? '');

        if ($phase === 'email_created_not_submitted' || $errorCode === 'stalwart_submission_not_created') {
            return 'not_created';
        }

        // A persisted provider submission id is the acceptance evidence
        // returned by EmailSubmission/set. An unknown send without it must
        // verify the provider's EmailSubmission record before trusting Sent.
        if ((string) ($attempt->getAttribute('provider_draft_id') ?? '') !== '') {
            return 'accepted';
        }

        return 'unknown';
    }

    private function markConfirmedSent(SendAttempt $attempt, SendReconciliationResult $result): void
    {
        $send = $result->sendResult;
        $remote = $result->message;
        if (! $send instanceof SendResult && $remote !== null) {
            $send = new SendResult(
                remoteMessageId: $remote->remoteMessageId,
                remoteThreadId: $remote->remoteThreadId,
                internetMessageId: $remote->internetMessageId,
                phase: 'reconciled_sent',
            );
        }

        if (! $send instanceof SendResult) {
            $this->moveToManualReview($attempt, 'provider_reconciliation_missing_message');

            return;
        }

        $this->finalizer->handle($attempt, $send, $remote);
    }

    private function workspaceConsistent(SendAttempt $attempt): bool
    {
        $workspaceId = (int) $attempt->getAttribute('workspace_id');
        $mailbox = $attempt->mailbox;
        $message = $attempt->message;
        $recipient = $attempt->recipient;
        $connection = $mailbox instanceof Mailbox ? $mailbox->connection : null;

        return $workspaceId > 0
            && $mailbox !== null
            && $message !== null
            && $connection instanceof ProviderConnection
            && (int) $mailbox->getAttribute('workspace_id') === $workspaceId
            && (int) $message->getAttribute('workspace_id') === $workspaceId
            && (int) $connection->getAttribute('workspace_id') === $workspaceId
            && (int) $attempt->getAttribute('mailbox_id') === (int) $mailbox->getAttribute('id')
            && (int) $attempt->getAttribute('message_id') === (int) $message->getAttribute('id')
            && (int) $mailbox->getAttribute('provider_connection_id') === (int) $connection->getAttribute('id')
            && (int) $message->getAttribute('mailbox_id') === (int) $mailbox->getAttribute('id')
            && ($recipient === null || (
                (int) $recipient->getAttribute('workspace_id') === $workspaceId
                && (int) Campaign::query()->whereKey((int) $recipient->getAttribute('campaign_id'))->value('workspace_id') === $workspaceId
                && (int) $recipient->getAttribute('mailbox_id') === (int) $mailbox->getAttribute('id')
            ));
    }
}
