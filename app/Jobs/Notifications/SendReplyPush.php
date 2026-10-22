<?php

namespace App\Jobs\Notifications;

use App\Domain\Mail\Services\UniboxInbox;
use App\Domain\Notifications\PushGateway;
use App\Domain\Notifications\PushMessage;
use App\Domain\Notifications\PushResult;
use App\Models\DeviceSession;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;

class SendReplyPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public function __construct(public string $deviceSessionId, public int $threadId) {}

    public function handle(PushGateway $gateway, UniboxInbox $inbox): void
    {
        $device = DeviceSession::query()->active()->find($this->deviceSessionId);
        $thread = Thread::query()->with('mailbox')->find($this->threadId);

        if (! $device instanceof DeviceSession || $device->apns_token === null || ! $thread instanceof Thread) {
            return;
        }

        $user = User::query()->find($device->user_id);

        // Membership can change between dispatch and delivery.
        if (! $user instanceof User
            || ! $user->workspaces()->whereKey((int) $thread->getAttribute('workspace_id'))->exists()) {
            return;
        }

        $sender = $thread->participant_name ?: ($thread->participant_email ?: 'New reply');
        $mailbox = $thread->mailbox?->email_address;

        $result = $gateway->send($device->apns_token, $device->apns_environment === 'sandbox' ? 'sandbox' : 'production', new PushMessage(
            title: Str::limit($sender, 80),
            subtitle: $mailbox !== null ? 'via '.$mailbox : null,
            body: Str::limit((string) ($thread->subject ?: '(no subject)'), 160),
            badge: $inbox->badgeFor($user),
            threadGroup: 'outboss-thread-'.$thread->id,
            collapseId: 'thread-'.$thread->id,
            data: [
                'workspace_id' => (int) $thread->getAttribute('workspace_id'),
                'thread_id' => $thread->id,
            ],
        ));

        if ($result === PushResult::Unregistered) {
            $device->forceFill(['apns_token' => null, 'apns_environment' => null])->save();
        }

        if ($result === PushResult::Retryable) {
            throw new RuntimeException('APNs asked us to retry this notification later.');
        }
    }
}
