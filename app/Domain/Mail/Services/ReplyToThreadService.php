<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\DTOs\OutgoingMessage;
use App\Domain\Mail\DTOs\RemoteMessageReference;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Domain\Mail\Exceptions\ClientOperationConflict;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Exceptions\SendOperationIncomplete;
use App\Domain\Mail\Providers\ProviderRegistry;
use App\Domain\Mail\Providers\StalwartJmapException;
use App\Domain\Mail\Support\ClientOperationId;
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

class ReplyToThreadService
{
    public function __construct(
        private ProviderRegistry $registry,
        private EnsureConnectionUsable $tokens,
        private HtmlSanitizer $sanitizer,
        private MailboxSenderIdentityService $senderIdentities,
    ) {}

    public function handle(User $user, Thread $thread, string $body, string $operationId): SendAttempt
    {
        $operationId = ClientOperationId::validate($operationId);
        $workspaceId = (int) $user->currentWorkspace()->id;

        return Cache::lock(
            'mail:client-operation:'.hash('sha256', $workspaceId.'|'.SendAttemptKind::Reply->value.'|'.$operationId),
            300,
        )->block(60, fn (): SendAttempt => $this->handleLocked($user, $thread, $body, $operationId));
    }

    private function handleLocked(User $user, Thread $thread, string $body, string $operationId): SendAttempt
    {
        $workspace = $user->currentWorkspace();
        $workspaceId = (int) $workspace->id;

        if ((int) $thread->getAttribute('workspace_id') !== $workspaceId) {
            throw new RuntimeException('workspace_mismatch');
        }

        $existing = SendAttempt::query()
            ->where('workspace_id', $workspaceId)
            ->where('kind', SendAttemptKind::Reply)
            ->where('client_operation_id', $operationId)
            ->first();

        if ($existing) {
            $message = $existing->message;

            if (! $message instanceof Message
                || (int) $message->thread_id !== (int) $thread->id
                || $message->body_text !== $body) {
                throw new ClientOperationConflict;
            }

            if ($this->retryable($existing)) {
                $parent = $message->replyTo;

                if (! $parent instanceof Message || ! $this->dispatch($existing, $parent)) {
                    throw new SendOperationIncomplete;
                }

                $thread->forceFill([
                    'local_state' => ThreadLocalState::Done,
                    'is_unread' => false,
                    'last_message_at' => now(),
                ])->save();
            }

            return $this->confirmed($existing);
        }

        $parent = Message::query()
            ->where('thread_id', $thread->id)
            ->where('workspace_id', $workspaceId)
            ->orderByRaw('COALESCE(received_at, sent_at) DESC')
            ->orderByDesc('id')
            ->first();

        if (! $parent) {
            throw new RuntimeException('thread_empty');
        }

        $mailbox = Mailbox::query()
            ->whereKey($thread->mailbox_id)
            ->where('workspace_id', $workspaceId)
            ->first();

        if (! $mailbox) {
            throw new RuntimeException('mailbox_disconnected');
        }

        $sender = $this->senderIdentities->forReply($mailbox);

        $attempt = DB::transaction(function () use ($user, $thread, $workspaceId, $body, $operationId, $parent, $sender) {
            $html = nl2br(e($body));
            $message = Message::query()->create([
                'workspace_id' => $workspaceId,
                'local_key' => (string) Str::uuid(),
                'thread_id' => $thread->id,
                'mailbox_id' => $thread->mailbox_id,
                'campaign_recipient_id' => $thread->campaign_recipient_id,
                'reply_to_message_id' => $parent->id,
                'client_message_marker' => (string) Str::uuid(),
                'in_reply_to' => $parent->internet_message_id,
                'direction' => MessageDirection::Outbound,
                'status' => MessageStatus::Queued,
                'from_email' => $sender['email'],
                'from_name' => $sender['name'],
                'to_recipients' => [['email' => $thread->participant_email]],
                'cc_recipients' => [],
                'bcc_recipients' => [],
                'subject' => $thread->subject,
                'body_text' => $body,
                'body_html_sanitized' => $this->sanitizer->sanitize($html),
                'rendered_payload' => json_encode(['body' => $body, 'parent' => $parent->id]),
                'provider_metadata' => [],
            ]);

            $attempt = SendAttempt::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'mailbox_id' => $thread->mailbox_id,
                'message_id' => $message->id,
                'campaign_recipient_id' => $thread->campaign_recipient_id,
                'kind' => SendAttemptKind::Reply,
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

        if ($this->dispatch($attempt, $parent)) {
            $thread->forceFill([
                'local_state' => ThreadLocalState::Done,
                'is_unread' => false,
                'last_message_at' => now(),
            ])->save();
        }

        return $this->confirmed($attempt);
    }

    private function dispatch(SendAttempt $attempt, Message $parent): bool
    {
        $mailbox = Mailbox::query()->find($attempt->mailbox_id);
        $connection = $mailbox
            ? ProviderConnection::query()->find($mailbox->provider_connection_id)
            : null;
        $message = Message::query()->find($attempt->message_id);
        $parentThread = Thread::query()->find($parent->thread_id);

        if (! $mailbox || ! $connection || ! $message || ! $parentThread
            || ! $this->workspaceConsistent($attempt, $mailbox, $message, $parent, $parentThread, $connection)) {
            $this->failForWorkspaceMismatch($attempt);

            return false;
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

            $result = $this->registry->transport($connection->provider)->sendReply(
                $mailbox,
                new RemoteMessageReference(
                    remoteMessageId: (string) $parent->remote_message_id,
                    remoteThreadId: $parentThread->remote_thread_id,
                    internetMessageId: $parent->internet_message_id,
                    subject: $parent->subject,
                ),
                new OutgoingMessage(
                    subject: $message->subject,
                    bodyText: $message->body_text,
                    bodyHtml: $message->body_html_sanitized ?: nl2br(e($message->body_text)),
                    to: $message->outboundRecipients(),
                    fromEmail: $message->from_email,
                    fromName: $message->from_name,
                    clientMessageMarker: $message->client_message_marker,
                    inReplyTo: $parent->internet_message_id,
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

        return true;
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
        Message $parent,
        Thread $parentThread,
        ProviderConnection $connection,
    ): bool {
        $workspaceId = (int) $attempt->getAttribute('workspace_id');

        if ($workspaceId <= 0
            || (int) $mailbox->getAttribute('workspace_id') !== $workspaceId
            || (int) $connection->getAttribute('workspace_id') !== $workspaceId
            || (int) $message->getAttribute('workspace_id') !== $workspaceId
            || (int) $parent->getAttribute('workspace_id') !== $workspaceId
            || (int) $parentThread->getAttribute('workspace_id') !== $workspaceId
            || (int) $attempt->getAttribute('mailbox_id') !== (int) $mailbox->id
            || (int) $attempt->getAttribute('message_id') !== (int) $message->id
            || (int) $message->getAttribute('mailbox_id') !== (int) $mailbox->id
            || (int) $parent->getAttribute('mailbox_id') !== (int) $mailbox->id
            || (int) $parentThread->getAttribute('mailbox_id') !== (int) $mailbox->id
            || (int) $parent->getAttribute('thread_id') !== (int) $parentThread->id) {
            return false;
        }

        $recipientId = $attempt->getAttribute('campaign_recipient_id')
            ?? $message->getAttribute('campaign_recipient_id')
            ?? $parent->getAttribute('campaign_recipient_id');

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
