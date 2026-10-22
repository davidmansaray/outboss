<?php

namespace App\Jobs\Mail;

use App\Domain\Mail\Services\ReconcileUnknownSend as ReconcileUnknownSendService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileUnknownSend implements ShouldQueue
{
    use Queueable;

    public function __construct(public ?int $attemptId = null)
    {
        $this->onQueue('low');
    }

    public function handle(ReconcileUnknownSendService $reconcile): void
    {
        $reconcile->handle($this->attemptId);
    }
}
