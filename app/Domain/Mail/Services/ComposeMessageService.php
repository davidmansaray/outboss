<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\DTOs\OutgoingMessage;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Domain\Mail\Exceptions\ClientOperationConflict;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Exceptions\SendOperationIncomplete;
use App\Domain\Mail\Providers\ProviderRegistry;
use App\Domain\Mail\Providers\StalwartJmapException;
use App\Domain\Mail\Support\ClientOperationId;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\Mail\Support\HtmlSanitizer;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\OutboxEvent;
use App\Models\ProviderConnection;
use App\Models\SendAttempt;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ComposeMessageService
{
    public function __construct(
        private ProviderRegistry $registry,
        private EnsureConnectionUsable $tokens,
        private HtmlSanitizer $sanitizer,
        private MailboxSenderIdentityService $senderIdentities,
    ) {}

    public function handle(User $user, Mailbox $mailbox, string $to, string $subject, string $body, string $operationId, SendAttemptKind $kind = SendAttemptKind::Compose): SendAttempt
    {
        $operationId = ClientOperationId::validate($operationId);
        $workspaceId = (int) $user->currentWorkspace()->id;

        return Cache::lock(
            'mail:client-operation:'.hash('sha256', $workspaceId.'|'.$kind->value.'|'.$operationId),
            300,
        )->block(60, fn (): SendAttempt => $this->handleLocked(
            $user,
            $mailbox,
            $to,
            $subject,
            $body,
            $operationId,
            $kind,
        ));
    }

    private function handleLocked(User $user, Mailbox $mailbox, string $to, string $subject, string $body, string $operationId, SendAttemptKind $kind): SendAttempt
    {
        $workspace = $user->currentWorkspace();
        $workspaceId = (int) $workspace->id;

        if ((int) $mailbox->getAttribute('workspace_id') !== $workspaceId) {
            throw new RuntimeException('workspace_mismatch');
        }

        $existing = SendAttempt::query()
            ->where('workspace_id', $workspaceId)
            ->where('kind', $kind)
            ->where('client_operation_id', $operationId)
            ->first();

        if ($existing) {
            $message = $existing->message;
            $samePayload = $message instanceof Message
                && (int) $existing->mailbox_id === (int) $mailbox->id
                && EmailNormalizer::normalize((string) data_get($message->to_recipients, '0.email')) === EmailNormalizer::normalize($to)
                && $message->subject === EmailNormalizer::stripHeaderInjection($subject)
                && $message->body_text === $body;

            if (! $samePayload) {
                throw new ClientOperationConflict;
            }

            if ($this->retryable($existing)) {
                $this->dispatch($existing);
            }

            return $this->confirmed($existing);
        }

        $sender = $this->senderIdentities->effective($mailbox);

        $attempt = DB::transaction(function () use ($user, $mailbox, $workspaceId, $to, $subject, $body, $operationId, $kind, $sender) {
            $thread = Thread::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'mailbox_id' => $mailbox->id,
                'local_key' => (string) Str::uuid(),
                'subject' => EmailNormalizer::stripHeaderInjection($subject),
                'subject_normalized' => EmailNormalizer::normalizeSubject($subject),
                'participant_email' => EmailNormalizer::normalize($to),
                'classification' => ThreadClassification::Unknown,
                'local_state' => ThreadLocalState::Open,
            ]);

            $html = nl2br(e($body));
            $message = Message::query()->create([
                'workspace_id' => $workspaceId,
                'local_key' => (string) Str::uuid(),
                'thread_id' => $thread->id,
                'mailbox_id' => $mailbox->id,
                'client_message_marker' => (string) Str::uuid(),
                'direction' => MessageDirection::Outbound,
                'status' => MessageStatus::Queued,
                'from_email' => $sender['email'],
                'from_name' => $sender['name'],
                'to_recipients' => [['email' => EmailNormalizer::normalize($to)]],
                'cc_recipients' => [],
                'bcc_recipients' => [],
                'subject' => EmailNormalizer::stripHeaderInjection($subject),
                'body_text' => $body,
                'body_html_sanitized' => $this->sanitizer->sanitize($html),
                'rendered_payload' => json_encode(['to' => $to, 'subject' => $subject, 'body' => $body]),
                'provider_metadata' => [],
            ]);

            $attempt = SendAttempt::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'mailbox_id' => $mailbox->id,
                'message_id' => $message->id,
                'kind' => $kind,
                'idempotency_key' => (string) Str::uuid(),
                'client_operation_id' => $operationId,
                'status' => SendAttemptStatus::Queued,
            ]);

            OutboxEvent::query()->create([
                'type' => 'send_attempt.created',
                'aggregate_type' => 'send_attempt',
                'aggregate_id' => $attempt->id,
                'payload' => ['send_attempt_id' => $attempt->id],
                'available_at' => now(),
            ]);

            return $attempt;
        });

        $this->dispatch($attempt);

        return $this->confirmed($attempt);
    }

    public function dispatch(SendAttempt $attempt): void
    {
        $mailbox = Mailbox::query()->find($attempt->mailbox_id);
        $message = Message::query()->find($attempt->message_id);
        $connection = $mailbox
            ? ProviderConnection::query()->find($mailbox->provider_connection_id)
            : null;

        if (! $mailbox || ! $message || ! $connection || ! $this->workspaceConsistent($attempt, $mailbox, $message, $connection)) {
            $this->failForWorkspaceMismatch($attempt);

            return;
        }

        try {
            $this->senderIdentities->assertSnapshotAllowed($mailbox, $message->from_email);
        } catch (SenderIdentityUnavailable $exception) {
            $attempt->forceFill([
                'status' => SendAttemptStatus::Deferred,
                'deferred_reason' => 'sender_identity_unavailable',
                'error_code' => 'sender_identity_unavailable',
                'safe_error_message' => SenderIdentityUnavailable::USER_MESSAGE,
            ])->save();

            throw $exception;
        }

        try {
            $this->tokens->handle($connection);
            $connection->refresh();
        } catch (Throwable $exception) {
            $attempt->forceFill([
                'status' => SendAttemptStatus::Failed,
                'error_code' => 'pre_provider_failed',
                'safe_error_message' => 'The provider request was not submitted. This operation can be retried safely.',
            ])->save();

            throw $exception;
        }

        try {
            $attempt->forceFill([
                'status' => SendAttemptStatus::Sending,
                'attempt_count' => $attempt->attempt_count + 1,
            ])->save();

            $result = $this->registry->transport($connection->provider)->sendNew(
                $mailbox,
                new OutgoingMessage(
                    subject: $message->subject,
                    bodyText: $message->body_text,
                    bodyHtml: $message->body_html_sanitized ?: nl2br(e($message->body_text)),
                    to: $message->outboundRecipients(),
                    fromEmail: $message->from_email,
                    fromName: $message->from_name,
                    clientMessageMarker: $message->client_message_marker,
                ),
            );
        } catch (Throwable $exception) {
            $attributes = [
                'status' => SendAttemptStatus::Unknown,
                'error_code' => 'provider_result_unknown',
                'safe_error_message' => 'The provider may have accepted this message. Reconcile the send before retrying.',
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

            $attempt->forceFill($attributes)->save();

            throw $exception;
        }

        $submissionOnly = $result->isProviderSubmissionOnly();
        $acceptedAt = now();

        $message->forceFill([
            'remote_message_id' => $result->remoteMessageId,
            'internet_message_id' => $result->internetMessageId,
            'status' => $submissionOnly ? MessageStatus::ProviderAccepted : MessageStatus::Sent,
            'sent_at' => $submissionOnly ? null : $acceptedAt,
        ])->save();

        $message->thread?->forceFill([
            'remote_thread_id' => $result->remoteThreadId,
            'last_message_at' => now(),
        ])->save();

        $attempt->forceFill([
            'status' => $submissionOnly ? SendAttemptStatus::ProviderAccepted : SendAttemptStatus::Sent,
            'deferred_reason' => null,
            'error_code' => null,
            'safe_error_message' => null,
            'provider_accepted_at' => $acceptedAt,
            'sent_at' => $submissionOnly ? null : $acceptedAt,
            'provider_phase' => $result->phase,
            'provider_draft_id' => $result->providerDraftId,
            'provider_request_id' => $result->providerRequestId,
            'provider_marker' => is_string($result->metadata['client_message_marker'] ?? null)
                ? $result->metadata['client_message_marker']
                : $attempt->getAttribute('provider_marker'),
        ])->save();
    }

    private function confirmed(SendAttempt $attempt): SendAttempt
    {
        $attempt = $attempt->fresh('message');

        if (! $attempt || ! in_array($attempt->getAttribute('status'), [
            SendAttemptStatus::ProviderAccepted,
            SendAttemptStatus::Sent,
        ], true)) {
            throw new SendOperationIncomplete;
        }

        return $attempt;
    }

    private function retryable(SendAttempt $attempt): bool
    {
        $status = $attempt->getAttribute('status');

        return in_array($status, [SendAttemptStatus::Queued, SendAttemptStatus::Deferred], true)
            || ($status === SendAttemptStatus::Failed && $attempt->error_code === 'pre_provider_failed');
    }

    private function workspaceConsistent(
        SendAttempt $attempt,
        Mailbox $mailbox,
        Message $message,
        ProviderConnection $connection,
    ): bool {
        $workspaceId = (int) $attempt->getAttribute('workspace_id');

        if ($workspaceId <= 0
            || (int) $mailbox->getAttribute('workspace_id') !== $workspaceId
            || (int) $message->getAttribute('workspace_id') !== $workspaceId
            || (int) $connection->getAttribute('workspace_id') !== $workspaceId
            || (int) $attempt->getAttribute('mailbox_id') !== (int) $mailbox->id
            || (int) $attempt->getAttribute('message_id') !== (int) $message->id
            || (int) $message->getAttribute('mailbox_id') !== (int) $mailbox->id) {
            return false;
        }

        $thread = $message->thread()->first();
        if (! $thread
            || (int) $thread->getAttribute('workspace_id') !== $workspaceId
            || (int) $thread->getAttribute('mailbox_id') !== (int) $mailbox->id) {
            return false;
        }

        $recipientId = $attempt->getAttribute('campaign_recipient_id')
            ?? $message->getAttribute('campaign_recipient_id');

        if ($recipientId === null) {
            return true;
        }

        $recipient = CampaignRecipient::query()->find((int) $recipientId);
        $campaign = $recipient
            ? Campaign::query()->find((int) $recipient->getAttribute('campaign_id'))
            : null;

        return $recipient !== null
            && $campaign !== null
            && (int) $recipient->getAttribute('workspace_id') === $workspaceId
            && (int) $recipient->getAttribute('mailbox_id') === (int) $mailbox->id
            && (int) $campaign->getAttribute('workspace_id') === $workspaceId
            && (int) $campaign->getAttribute('id') > 0;
    }

    private function failForWorkspaceMismatch(SendAttempt $attempt): void
    {
        $attempt->forceFill([
            'status' => SendAttemptStatus::Failed,
            'error_code' => 'workspace_mismatch',
            'safe_error_message' => 'Send blocked because its records belong to different workspaces.',
            'provider_phase' => null,
        ])->save();
    }
}
