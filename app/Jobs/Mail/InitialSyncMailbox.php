<?php

namespace App\Jobs\Mail;

use App\Models\Mailbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class InitialSyncMailbox implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $mailboxId)
    {
        $this->onQueue('mail-sync');
    }

    public function handle(): void
    {
        $mailbox = Mailbox::query()->find($this->mailboxId);

        if (! $mailbox) {
            return;
        }

        if ($mailbox->sync_requested_at === null) {
            $mailbox->forceFill(['sync_requested_at' => now()])->save();
        }

        SyncMailbox::dispatch($mailbox->id);
    }
}
