<?php

namespace App\Domain\Notifications;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Devices\Enums\PushPreference;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Jobs\Notifications\SendReplyPush;
use App\Models\DeviceSession;
use App\Models\Message;
use App\Models\Thread;
use App\Models\WorkspaceMembership;

/**
 * Decides which signed-in phones hear about a new inbound reply. Only fresh
 * replies that land in Needs reply alert, so a first mailbox sync or a
 * backfill never floods a phone.
 */
class ReplyNotificationDispatcher
{
    public function __construct(private PushConfiguration $push) {}

    public function inboundMessageStored(Message $message, bool $isNewMessage): void
    {
        if (! $isNewMessage
            || $message->getAttribute('direction') !== MessageDirection::Inbound
            || ! $this->push->available()) {
            return;
        }

        $receivedAt = $message->getAttribute('received_at') ?? $message->getAttribute('created_at');
        $freshFor = (int) config('outboss.push.fresh_reply_minutes', 15);

        if (! $receivedAt instanceof \DateTimeInterface
            || $receivedAt < now()->subMinutes($freshFor)) {
            return;
        }

        $thread = Thread::query()->with('recipient')->find((int) $message->getAttribute('thread_id'));

        if (! $thread instanceof Thread
            || $thread->getAttribute('local_state') !== ThreadLocalState::NeedsAction) {
            return;
        }

        $workspaceId = (int) $thread->getAttribute('workspace_id');
        $isLead = $thread->recipient?->getAttribute('status') === RecipientStatus::Interested;
        $memberIds = WorkspaceMembership::query()->where('workspace_id', $workspaceId)->pluck('user_id');

        DeviceSession::query()
            ->active()
            ->whereIn('user_id', $memberIds)
            ->whereNotNull('apns_token')
            ->where('notify', '!=', PushPreference::Off->value)
            ->get()
            ->filter(fn (DeviceSession $device): bool => $device->notify === PushPreference::Needs || $isLead)
            ->each(fn (DeviceSession $device) => SendReplyPush::dispatch($device->id, $thread->id)->afterCommit());
    }
}
