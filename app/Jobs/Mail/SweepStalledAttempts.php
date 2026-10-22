<?php

namespace App\Jobs\Mail;

use App\Domain\Mail\Services\SweepStalledAttempts as SweepStalledAttemptsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SweepStalledAttempts implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(SweepStalledAttemptsService $sweeper): void
    {
        $sweeper->handle();
    }
}
