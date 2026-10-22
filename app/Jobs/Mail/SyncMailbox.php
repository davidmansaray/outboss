<?php

namespace App\Jobs\Mail;

use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\SyncStatus;
use App\Domain\Mail\Services\SyncMailboxService;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncMailbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 480;

    public int $uniqueFor = 600;

    public function __construct(public int $mailboxId)
    {
        $this->onQueue('mail-sync');
    }

    public function uniqueId(): string
    {
        return 'mailbox:'.$this->mailboxId;
    }

    public function handle(SyncMailboxService $sync): void
    {
        $sync->handle($this->mailboxId);
    }

    public function failed(?Throwable $exception): void
    {
        $message = mb_substr(
            $exception?->getMessage() ?: 'Sync interrupted',
            0,
            180,
        );

        MailboxSyncState::query()
            ->where('mailbox_id', $this->mailboxId)
            ->where('status', SyncStatus::Syncing->value)
            ->update([
                'status' => SyncStatus::Failed->value,
                'last_error' => $message,
            ]);

        Mailbox::query()
            ->whereKey($this->mailboxId)
            ->where('status', ConnectionStatus::Connected->value)
            ->update([
                'last_sync_error' => $message,
                'sync_requested_at' => null,
            ]);
    }
}
