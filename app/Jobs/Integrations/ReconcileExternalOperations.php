<?php

namespace App\Jobs\Integrations;

use App\Domain\Integrations\Operations\ReconcileExternalOperations as ReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Reconcile due neutral integration operations and finalize stale holds. */
final class ReconcileExternalOperations implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue((string) config('integrations.queue', 'low'));
    }

    public function uniqueId(): string
    {
        return 'integrations-reconcile-external-operations';
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ReconciliationService $reconciliation): void
    {
        $reconciliation->handle();
    }
}
