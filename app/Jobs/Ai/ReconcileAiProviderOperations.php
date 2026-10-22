<?php

namespace App\Jobs\Ai;

use App\Domain\Ai\Services\ReconcileAiProviderOperations as ReconciliationService;
use App\Domain\Ai\Services\WipeRetiredAiCredentials;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileAiProviderOperations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue((string) config('ai.queue'));
    }

    public function handle(ReconciliationService $reconciliation, WipeRetiredAiCredentials $retiredCredentials): void
    {
        $reconciliation->handle();
        $retiredCredentials->handle();
    }
}
