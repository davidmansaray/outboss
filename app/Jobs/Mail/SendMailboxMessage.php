<?php

namespace App\Jobs\Mail;

use App\Domain\Mail\Services\ComposeMessageService;
use App\Models\SendAttempt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendMailboxMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $attemptId)
    {
        $this->onQueue('mail-send');
    }

    public function handle(ComposeMessageService $compose): void
    {
        $attempt = SendAttempt::query()->find($this->attemptId);

        if ($attempt) {
            $compose->dispatch($attempt);
        }
    }
}
