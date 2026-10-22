<?php

namespace App\Domain\Mail\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Services\StopCampaignRecipient;
use App\Domain\Mail\Enums\BounceSubtype;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\SuppressionReason;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\Mail\Support\MessageIdNormalizer;
use App\Jobs\Objectives\ProjectGtmConversation;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SuppressionEntry;
use App\Models\Thread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApplyInboundCampaignEffects
{
    public function __construct(
        private StopCampaignRecipient $stopRecipient,
        private EvaluateMailboxSendingHealth $health,
    ) {}

    public function handle(
        Message $message,
        bool $isNewMessage = true,
        bool $allowEmailFallback = true,
    ): void {
        if ($message->direction->value !== 'inbound') {
            return;
        }

        $mailbox = $message->relationLoaded('mailbox')
            ? $message->mailbox
            : $message->mailbox()->first();
        $workspaceId = (int) $message->getAttribute('workspace_id');

        if (! $mailbox
            || $workspaceId <= 0
            || (int) $mailbox->getAttribute('workspace_id') !== $workspaceId) {
            return;
        }

        DB::transaction(function () use ($message, $mailbox, $isNewMessage, $allowEmailFallback, $workspaceId): void {
            $threadId = $message->getAttribute('thread_id');
            $thread = is_numeric($threadId)
                ? Thread::query()->find((int) $threadId)
                : null;

            if ($thread && ((int) $thread->getAttribute('workspace_id') !== $workspaceId
                || (int) $thread->getAttribute('mailbox_id') !== (int) $mailbox->getAttribute('id'))) {
                return;
            }

            $previousRecipientId = $message->getAttribute('campaign_recipient_id');
            $recipient = $this->linkRecipient($message, $thread, $allowEmailFallback, $workspaceId);
            $linkChanged = $recipient !== null
                && (int) $previousRecipientId !== $recipient->id;

            if ($recipient) {
                $thread?->forceFill(['campaign_recipient_id' => $recipient->id])->save();
                $message->forceFill(['campaign_recipient_id' => $recipient->id])->save();
            }

            if ($recipient && ! in_array($message->getRawOriginal('classification'), [
                ThreadClassification::Bounce->value,
                ThreadClassification::OutOfOffice->value,
                ThreadClassification::Unsubscribe->value,
            ], true)) {
                $message->forceFill(['classification' => ThreadClassification::Human])->save();
            }

            $updatesAggregate = ($isNewMessage || $linkChanged)
                && $this->isChronologicallyNewest($message, $thread);

            if ($updatesAggregate) {
                $thread->forceFill([
                    'classification' => $message->classification,
                    'bounce_subtype' => $message->bounce_subtype,
                    'classification_confidence' => $message->classification_confidence,
                ])->save();
            }

            match ($message->classification) {
                ThreadClassification::Human => $this->stop(
                    $recipient,
                    $thread,
                    RecipientStatus::Replied,
                    'human_reply',
                    ThreadLocalState::NeedsAction,
                    updateThread: $updatesAggregate,
                ),
                ThreadClassification::OutOfOffice => $updatesAggregate
                    ? $thread?->forceFill(['local_state' => ThreadLocalState::OutOfOffice])->save()
                    : null,
                ThreadClassification::Bounce => $this->bounce($recipient, $thread, $message, $updatesAggregate),
                ThreadClassification::Unsubscribe => $this->unsubscribe($recipient, $thread, $message, $updatesAggregate),
                default => null,
            };

            if ($message->getRawOriginal('classification') === ThreadClassification::Human->value
                && is_numeric($message->getAttribute('thread_id'))) {
                $threadId = (int) $message->getAttribute('thread_id');
                $messageId = (int) $message->getKey();
                DB::afterCommit(static function () use ($workspaceId, $threadId, $messageId): void {
                    try {
                        ProjectGtmConversation::dispatch($workspaceId, $threadId, $messageId);
                    } catch (Throwable $exception) {
                        Log::warning('Objective conversation projection dispatch failed.', [
                            'workspace_id' => $workspaceId,
                            'thread_id' => $threadId,
                            'message_id' => $messageId,
                            'exception' => $exception::class,
                        ]);
                    }
                });
            }
        });
    }

    public function triage(Thread $thread, RecipientStatus $status): void
    {
        if (! $this->threadWorkspaceConsistent($thread)) {
            return;
        }

        DB::transaction(function () use ($thread, $status): void {
            $recipient = $this->recipientForThread($thread);
            $previous = $this->recipientStatus($recipient);

            if ($recipient) {
                $reason = $status === RecipientStatus::Replied
                    ? 'human_reply'
                    : $status->value;
                $this->stop(
                    $recipient,
                    $thread,
                    $status,
                    $reason,
                    ThreadLocalState::Done,
                    allowCorrection: true,
                );
            } else {
                $thread->forceFill(['local_state' => ThreadLocalState::Done])->save();
            }

            if ($previous === RecipientStatus::Bounced && $this->isReplyFamily($status)) {
                $this->clearFalseBounceSuppressions($thread);
            }
        });
    }

    public function treatAsReply(Thread $thread): void
    {
        $this->triage($thread, RecipientStatus::Replied);
    }

    public function removeBadAddress(Thread $thread): void
    {
        if (! $this->threadWorkspaceConsistent($thread)) {
            return;
        }

        $recipient = $this->recipientForThread($thread);

        if ($recipient) {
            $this->stop($recipient, $thread, RecipientStatus::Removed, 'bad_address', ThreadLocalState::Problem, allowCorrection: true);
            $campaign = $recipient->campaign()
                ->where('workspace_id', $thread->getAttribute('workspace_id'))
                ->first();

            if (! $campaign) {
                return;
            }

            SuppressionEntry::query()->firstOrCreate(
                [
                    'user_id' => $campaign->getAttribute('user_id'),
                    'workspace_id' => $campaign->getAttribute('workspace_id'),
                    'email_normalized' => $recipient->email_normalized,
                ],
                [
                    'email_address' => $recipient->email_address,
                    'reason' => SuppressionReason::Manual,
                    'source_message_id' => $thread->messages()
                        ->where('workspace_id', $thread->getAttribute('workspace_id'))
                        ->reorder()
                        ->orderByRaw('COALESCE(received_at, sent_at) DESC')
                        ->orderByDesc('id')
                        ->value('id'),
                ],
            );
        }
    }

    public function removeFromCampaign(Thread $thread): void
    {
        if (! $this->threadWorkspaceConsistent($thread)) {
            return;
        }

        $recipient = $this->recipientForThread($thread);

        if ($recipient) {
            $this->stop($recipient, $thread, RecipientStatus::Removed, 'removed', ThreadLocalState::Done, allowCorrection: true);
        }
    }

    private function linkRecipient(
        Message $message,
        ?Thread $thread,
        bool $allowEmailFallback,
        int $workspaceId,
    ): ?CampaignRecipient {
        if ($thread?->campaign_recipient_id) {
            $recipient = CampaignRecipient::query()
                ->whereKey($thread->campaign_recipient_id)
                ->where('workspace_id', $workspaceId)
                ->where('mailbox_id', $message->mailbox_id)
                ->whereHas('campaign', fn ($campaign) => $campaign->where('workspace_id', $workspaceId))
                ->first();

            if ($recipient?->mailbox_id === $message->mailbox_id) {
                return $recipient;
            }
        }

        $recipient = $this->recipientFromReplyHeaders($message, $workspaceId);

        if ($recipient) {
            return $recipient;
        }

        if (! $allowEmailFallback) {
            return null;
        }

        $email = EmailNormalizer::normalize($message->from_email);

        if ($email === '') {
            return null;
        }

        $candidates = CampaignRecipient::query()
            ->where('workspace_id', $workspaceId)
            ->where('mailbox_id', $message->mailbox_id)
            ->whereHas('campaign', fn ($campaign) => $campaign->where('workspace_id', $workspaceId))
            ->where('email_normalized', $email)
            ->where('status', '!=', RecipientStatus::Removed)
            ->where('created_at', '>=', now()->subDays(90))
            ->get();

        if ($candidates->count() !== 1) {
            return null;
        }

        return CampaignRecipient::query()
            ->whereKey($candidates->value('id'))
            ->where('workspace_id', $workspaceId)
            ->where('mailbox_id', $message->mailbox_id)
            ->whereHas('campaign', fn ($campaign) => $campaign->where('workspace_id', $workspaceId))
            ->first();
    }

    private function recipientFromReplyHeaders(Message $message, int $workspaceId): ?CampaignRecipient
    {
        $references = $message->getAttribute('references');

        if (! is_array($references)) {
            $references = [];
        }

        $replyIds = MessageIdNormalizer::normalizeList([
            $message->in_reply_to,
            ...$references,
        ]);

        if ($replyIds === []) {
            return null;
        }

        $storedReplyIds = [];
        foreach ($replyIds as $replyId) {
            $storedReplyIds[] = $replyId;
            $storedReplyIds[] = '<'.$replyId.'>';
        }

        $parents = Message::query()
            ->where('workspace_id', $workspaceId)
            ->where('mailbox_id', $message->mailbox_id)
            ->where('direction', 'outbound')
            ->whereNotNull('internet_message_id')
            ->whereNotNull('campaign_recipient_id')
            ->whereIn(DB::raw('LOWER(TRIM(internet_message_id))'), $storedReplyIds)
            ->with('recipient')
            ->get();

        foreach ($replyIds as $replyId) {
            $recipientIds = $parents
                ->filter(fn (Message $parent): bool => MessageIdNormalizer::normalize($parent->internet_message_id) === $replyId)
                ->pluck('campaign_recipient_id')
                ->filter()
                ->unique()
                ->values();

            if ($recipientIds->count() === 1) {
                return CampaignRecipient::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('mailbox_id', $message->mailbox_id)
                    ->whereHas('campaign', fn ($campaign) => $campaign->where('workspace_id', $workspaceId))
                    ->whereKey($recipientIds->first())
                    ->first();
            }
        }

        return null;
    }

    private function bounce(
        ?CampaignRecipient $recipient,
        ?Thread $thread,
        Message $message,
        bool $updateThread,
    ): void {
        $this->stop(
            $recipient,
            $thread,
            RecipientStatus::Bounced,
            'bounce',
            ThreadLocalState::Problem,
            $updateThread,
        );

        if ($recipient && $message->bounce_subtype === BounceSubtype::Hard) {
            $this->suppress($recipient, SuppressionReason::HardBounce, $message);
            $mailbox = $message->mailbox;

            if ($mailbox instanceof Mailbox) {
                $this->health->handle($mailbox);
            }
        }
    }

    private function unsubscribe(
        ?CampaignRecipient $recipient,
        ?Thread $thread,
        Message $message,
        bool $updateThread,
    ): void {
        $this->stop(
            $recipient,
            $thread,
            RecipientStatus::Unsubscribed,
            'unsubscribe',
            ThreadLocalState::Problem,
            $updateThread,
        );

        $email = $recipient?->email_normalized ?? EmailNormalizer::normalize($message->from_email);
        if ($email !== '') {
            $mailbox = $message->mailbox;

            if (! $mailbox
                || (int) $mailbox->getAttribute('workspace_id') !== (int) $message->getAttribute('workspace_id')) {
                return;
            }

            SuppressionEntry::query()->firstOrCreate(
                [
                    'user_id' => $mailbox->getAttribute('user_id'),
                    'workspace_id' => $message->getAttribute('workspace_id'),
                    'email_normalized' => $email,
                ],
                [
                    'email_address' => $message->from_email ?? $email,
                    'reason' => SuppressionReason::Unsubscribe,
                    'source_message_id' => $message->id,
                ],
            );
        }
    }

    private function stop(
        ?CampaignRecipient $recipient,
        ?Thread $thread,
        RecipientStatus $status,
        string $reason,
        ThreadLocalState $folder,
        bool $updateThread = true,
        bool $allowCorrection = false,
    ): void {
        if ($thread && ! $this->threadWorkspaceConsistent($thread)) {
            return;
        }

        $rawClassification = $thread?->getRawOriginal('classification');
        $threadClassification = $this->isReplyFamily($status)
            ? ThreadClassification::Human
            : ThreadClassification::tryFrom(is_string($rawClassification) ? $rawClassification : '');

        if (! $recipient) {
            if ($updateThread) {
                $thread?->forceFill([
                    'local_state' => $folder,
                    'classification' => $threadClassification,
                ])->save();
            }

            return;
        }

        $recipientQuery = CampaignRecipient::query()
            ->whereKey($recipient->id);

        if ($thread) {
            $recipientQuery
                ->where('workspace_id', $thread->getAttribute('workspace_id'))
                ->where('mailbox_id', $thread->getAttribute('mailbox_id'))
                ->whereHas('campaign', fn ($campaign) => $campaign->where('workspace_id', $thread->getAttribute('workspace_id')));
        }

        $recipient = $recipientQuery
            ->lockForUpdate()
            ->first();

        if (! $recipient) {
            return;
        }

        if ($this->recipientStatus($recipient) === RecipientStatus::Removed
            || (! $allowCorrection && $recipient->status->isTerminal())) {
            if ($updateThread) {
                $thread?->forceFill([
                    'local_state' => $folder,
                    'classification' => $threadClassification,
                ])->save();
            }

            return;
        }

        if ($updateThread) {
            $thread?->forceFill([
                'local_state' => $folder,
                'classification' => $threadClassification,
            ])->save();

            if ($allowCorrection) {
                $this->correctNewestInboundClassification($thread, $threadClassification);
            }
        }

        if ($recipient->send_lease_token && $recipient->send_lease_expires_at?->isFuture()) {
            // Current leased send may finish; later steps still stop.
        }

        $this->stopRecipient->handle($recipient, $status, $reason, $allowCorrection);
    }

    private function recipientStatus(?CampaignRecipient $recipient): ?RecipientStatus
    {
        if (! $recipient) {
            return null;
        }

        $status = $recipient->getAttribute('status');

        return $status instanceof RecipientStatus
            ? $status
            : RecipientStatus::tryFrom((string) $status);
    }

    private function isReplyFamily(RecipientStatus $status): bool
    {
        return in_array($status, [
            RecipientStatus::Replied,
            RecipientStatus::Interested,
            RecipientStatus::NotNow,
            RecipientStatus::WrongPerson,
        ], true);
    }

    private function correctNewestInboundClassification(?Thread $thread, ?ThreadClassification $classification): void
    {
        if (! $thread || $classification !== ThreadClassification::Human) {
            return;
        }

        $newest = $thread->messages()
            ->where('workspace_id', $thread->getAttribute('workspace_id'))
            ->where('mailbox_id', $thread->getAttribute('mailbox_id'))
            ->where('direction', MessageDirection::Inbound)
            ->reorder()
            ->orderByRaw('COALESCE(received_at, sent_at) DESC')
            ->orderByDesc('id')
            ->first();

        if (! $newest instanceof Message) {
            return;
        }

        $newest->forceFill([
            'classification' => ThreadClassification::Human,
            'bounce_subtype' => null,
        ])->save();
    }

    private function clearFalseBounceSuppressions(Thread $thread): void
    {
        $messageIds = $thread->messages()
            ->where('workspace_id', $thread->getAttribute('workspace_id'))
            ->where('mailbox_id', $thread->getAttribute('mailbox_id'))
            ->pluck('id');

        if ($messageIds->isEmpty()) {
            return;
        }

        SuppressionEntry::query()
            ->where('workspace_id', $thread->getAttribute('workspace_id'))
            ->where('reason', SuppressionReason::HardBounce)
            ->whereIn('source_message_id', $messageIds)
            ->delete();
    }

    private function isChronologicallyNewest(Message $message, ?Thread $thread): bool
    {
        if (! $thread) {
            return false;
        }

        return (int) $thread->messages()
            ->reorder()
            ->orderByRaw('COALESCE(received_at, sent_at) DESC')
            ->orderByDesc('id')
            ->value('id') === $message->id;
    }

    private function suppress(CampaignRecipient $recipient, SuppressionReason $reason, Message $message): void
    {
        $workspaceId = (int) $message->getAttribute('workspace_id');
        if ($workspaceId <= 0 || (int) $recipient->getAttribute('workspace_id') !== $workspaceId) {
            return;
        }

        $campaign = $recipient->campaign()
            ->where('workspace_id', $workspaceId)
            ->first();

        if (! $campaign) {
            return;
        }

        SuppressionEntry::query()->firstOrCreate(
            [
                'user_id' => $campaign->getAttribute('user_id'),
                'workspace_id' => $campaign->getAttribute('workspace_id'),
                'email_normalized' => $recipient->email_normalized,
            ],
            [
                'email_address' => $recipient->email_address,
                'reason' => $reason,
                'source_message_id' => $message->id,
            ],
        );
    }

    private function recipientForThread(Thread $thread): ?CampaignRecipient
    {
        if (! $this->threadWorkspaceConsistent($thread)) {
            return null;
        }

        $workspaceId = (int) $thread->getAttribute('workspace_id');
        $mailboxId = (int) $thread->getAttribute('mailbox_id');
        $recipientId = $thread->getAttribute('campaign_recipient_id');
        $recipient = $recipientId === null
            ? null
            : CampaignRecipient::query()->whereKey((int) $recipientId)->first();

        if (! $recipient
            || $workspaceId <= 0
            || (int) $recipient->getAttribute('workspace_id') !== $workspaceId
            || (int) $recipient->getAttribute('mailbox_id') !== $mailboxId) {
            return null;
        }

        $campaign = Campaign::query()->whereKey((int) $recipient->getAttribute('campaign_id'))->first();

        return $campaign && (int) $campaign->getAttribute('workspace_id') === $workspaceId
            ? $recipient
            : null;
    }

    private function threadWorkspaceConsistent(Thread $thread): bool
    {
        $workspaceId = (int) $thread->getAttribute('workspace_id');
        $mailbox = $thread->relationLoaded('mailbox')
            ? $thread->mailbox
            : $thread->mailbox()->first();

        return $workspaceId > 0
            && $mailbox !== null
            && (int) $mailbox->getAttribute('workspace_id') === $workspaceId
            && (int) $thread->getAttribute('mailbox_id') === (int) $mailbox->getKey();
    }
}
