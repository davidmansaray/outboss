<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\DTOs\RemoteMessage;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\Mail\Support\HtmlSanitizer;
use App\Domain\Mail\Support\MessageIdNormalizer;
use App\Domain\Notifications\ReplyNotificationDispatcher;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Thread;
use Illuminate\Support\Str;
use RuntimeException;

class UpsertRemoteMessage
{
    public function __construct(
        private HtmlSanitizer $sanitizer,
        private ClassifyInboundMessage $classifier,
        private ApplyInboundCampaignEffects $effects,
        private ReplyNotificationDispatcher $notifications,
    ) {}

    public function handle(
        Mailbox $mailbox,
        RemoteMessage $remote,
        bool $allowEmailFallback = true,
    ): Message {
        $workspaceId = (int) $mailbox->getAttribute('workspace_id');

        if ($workspaceId <= 0) {
            throw new RuntimeException('workspace_mismatch');
        }

        if ($remote->deleted) {
            $existing = Message::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('remote_message_id', $remote->remoteMessageId)
                ->first();

            if ($existing && (int) $existing->getAttribute('workspace_id') !== $workspaceId) {
                throw new RuntimeException('workspace_mismatch');
            }

            return $existing ?? new Message;
        }

        $message = Message::query()->firstOrNew([
            'mailbox_id' => $mailbox->id,
            'remote_message_id' => $remote->remoteMessageId,
        ]);

        if ($message->exists && (int) $message->getAttribute('workspace_id') !== $workspaceId) {
            throw new RuntimeException('workspace_mismatch');
        }

        $isNewMessage = ! $message->exists;
        $thread = $this->thread($mailbox, $remote, $message);
        $classification = $remote->direction === MessageDirection::Inbound
            ? $this->classifier->handle($remote)
            : [
                'classification' => ThreadClassification::Unknown,
                'bounce_subtype' => null,
                'confidence' => null,
            ];

        if ($isNewMessage) {
            $message->local_key = (string) Str::uuid();
        }

        $internetMessageId = MessageIdNormalizer::normalize($remote->internetMessageId);
        $inReplyTo = MessageIdNormalizer::normalize($remote->inReplyTo);
        $references = MessageIdNormalizer::normalizeList($remote->references);
        $bodyHtml = $this->sanitizer->sanitize($remote->bodyHtml);
        $bodyText = $remote->bodyText;

        if (! $isNewMessage) {
            if ($remote->bodyHtml === null) {
                $existingHtml = $message->getAttribute('body_html_sanitized');
                $existingText = $message->getAttribute('body_text');
                $bodyHtml = is_string($existingHtml) && $existingHtml !== '' ? $existingHtml : $bodyHtml;
                $bodyText = is_string($existingText) && $existingText !== '' ? $existingText : $bodyText;
            }

            if ($internetMessageId === null) {
                $existingInternetMessageId = $message->getAttribute('internet_message_id');
                $internetMessageId = MessageIdNormalizer::normalize(
                    is_string($existingInternetMessageId) ? $existingInternetMessageId : null,
                );
            }

            if ($inReplyTo === null) {
                $existingInReplyTo = $message->getAttribute('in_reply_to');
                $inReplyTo = MessageIdNormalizer::normalize(
                    is_string($existingInReplyTo) ? $existingInReplyTo : null,
                );
            }

            $existingReferences = $message->getAttribute('references');
            if ($references === [] && is_array($existingReferences) && $existingReferences !== []) {
                $references = MessageIdNormalizer::normalizeList($existingReferences);
            }
        }

        $message->fill([
            'workspace_id' => $workspaceId,
            'thread_id' => $thread->id,
            'internet_message_id' => $internetMessageId,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'direction' => $remote->direction,
            'classification' => $classification['classification'],
            'bounce_subtype' => $classification['bounce_subtype'],
            'classification_confidence' => $classification['confidence'],
            'status' => $remote->direction === MessageDirection::Inbound
                ? MessageStatus::Received
                : MessageStatus::Sent,
            'from_name' => $remote->fromName,
            'from_email' => $remote->fromEmail,
            'to_recipients' => $remote->to,
            'cc_recipients' => $remote->cc,
            'bcc_recipients' => $remote->bcc,
            'subject' => EmailNormalizer::stripHeaderInjection($remote->subject),
            'body_html_sanitized' => $bodyHtml,
            'body_text' => $bodyText,
            'has_attachments' => $remote->hasAttachments,
            'provider_metadata' => $remote->metadata,
            'sent_at' => $remote->direction === MessageDirection::Outbound ? $remote->occurredAt : null,
            'received_at' => $remote->direction === MessageDirection::Inbound ? $remote->occurredAt : null,
        ]);
        $message->save();

        $occurredAt = $remote->occurredAt ?? now();
        $currentLastMessageAt = $thread->getAttribute('last_message_at');
        $updatesPreview = ! $currentLastMessageAt instanceof \DateTimeInterface
            || $occurredAt->getTimestamp() >= $currentLastMessageAt->getTimestamp();
        $threadAttributes = [
            'is_unread' => $remote->direction === MessageDirection::Inbound && $isNewMessage
                ? true
                : (bool) ($thread->is_unread ?? false),
        ];

        if ($updatesPreview) {
            $threadAttributes += [
                'subject' => $remote->subject !== '' ? $remote->subject : $thread->subject,
                'subject_normalized' => EmailNormalizer::normalizeSubject($remote->subject ?: $thread->subject),
                'snippet' => Str::limit($remote->bodyText, 140),
                'last_message_at' => $occurredAt,
            ];
            $threadAttributes += $this->participantAttributes($mailbox, $remote, $thread);
        }

        if ($remote->direction === MessageDirection::Inbound && $isNewMessage && $updatesPreview) {
            $threadAttributes += [
                'classification' => $classification['classification'],
                'bounce_subtype' => $classification['bounce_subtype'],
                'classification_confidence' => $classification['confidence'],
            ];

            $threadAttributes['local_state'] = $this->localState($classification['classification'], $thread->local_state);
        }

        $thread->forceFill($threadAttributes)->save();

        if ($remote->direction === MessageDirection::Inbound) {
            $this->effects->handle(
                $message->fresh(['thread', 'mailbox']),
                isNewMessage: $isNewMessage,
                allowEmailFallback: $allowEmailFallback,
            );

            // After campaign effects, so the thread's final state decides
            // whether this reply alerts a phone.
            $this->notifications->inboundMessageStored($message, $isNewMessage);

            return $message->refresh();
        }

        return $message;
    }

    /**
     * @return array{participant_name: ?string, participant_email: ?string}|array{}
     */
    private function participantAttributes(Mailbox $mailbox, RemoteMessage $remote, Thread $thread): array
    {
        if ($remote->direction === MessageDirection::Inbound) {
            return [
                'participant_name' => $remote->fromName,
                'participant_email' => $remote->fromEmail,
            ];
        }

        $participantEmail = $thread->getAttribute('participant_email');
        $normalizedParticipant = EmailNormalizer::normalize(
            is_string($participantEmail) ? $participantEmail : null,
        );
        $senderAddresses = array_filter([
            EmailNormalizer::normalize($mailbox->email_address),
            EmailNormalizer::normalize($remote->fromEmail),
        ]);

        if ($normalizedParticipant !== '' && ! in_array($normalizedParticipant, $senderAddresses, true)) {
            return [];
        }

        foreach ($remote->to as $recipient) {
            $email = $recipient['email'];

            if (! EmailNormalizer::isValid($email)) {
                continue;
            }

            $name = $recipient['name'] ?? null;

            return [
                'participant_name' => is_string($name) && trim($name) !== '' ? trim($name) : null,
                'participant_email' => EmailNormalizer::normalize($email),
            ];
        }

        return [];
    }

    private function thread(Mailbox $mailbox, RemoteMessage $remote, Message $message): Thread
    {
        $workspaceId = (int) $mailbox->getAttribute('workspace_id');

        if ($remote->remoteThreadId) {
            $existing = Thread::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('remote_thread_id', $remote->remoteThreadId)
                ->first();

            if ($existing) {
                if ((int) $existing->getAttribute('workspace_id') !== $workspaceId) {
                    throw new RuntimeException('workspace_mismatch');
                }

                return $existing;
            }

            return Thread::query()->create([
                'workspace_id' => $workspaceId,
                'user_id' => $mailbox->user_id,
                'mailbox_id' => $mailbox->id,
                'remote_thread_id' => $remote->remoteThreadId,
                'local_key' => (string) Str::uuid(),
                'subject' => $remote->subject,
                'subject_normalized' => EmailNormalizer::normalizeSubject($remote->subject),
                'classification' => ThreadClassification::Unknown,
                'local_state' => ThreadLocalState::Open,
            ]);
        }

        if ($message->exists && $message->thread_id) {
            $existing = Thread::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('workspace_id', $workspaceId)
                ->whereKey($message->thread_id)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return Thread::query()->create([
            'workspace_id' => $workspaceId,
            'user_id' => $mailbox->user_id,
            'mailbox_id' => $mailbox->id,
            'local_key' => (string) Str::uuid(),
            'subject' => $remote->subject,
            'subject_normalized' => EmailNormalizer::normalizeSubject($remote->subject),
            'classification' => ThreadClassification::Unknown,
            'local_state' => ThreadLocalState::Open,
        ]);
    }

    private function localState(ThreadClassification $classification, ThreadLocalState $current): ThreadLocalState
    {
        if ($current === ThreadLocalState::Done) {
            return ThreadLocalState::Done;
        }

        return match ($classification) {
            ThreadClassification::Human => ThreadLocalState::NeedsAction,
            ThreadClassification::OutOfOffice => ThreadLocalState::OutOfOffice,
            ThreadClassification::Bounce, ThreadClassification::Unsubscribe => ThreadLocalState::Problem,
            default => ThreadLocalState::Open,
        };
    }
}
