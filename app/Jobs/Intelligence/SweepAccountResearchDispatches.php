<?php

namespace App\Jobs\Intelligence;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Scheduler-friendly durable dispatch sweeper. */
final class SweepAccountResearchDispatches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly ?int $workspaceId = null) {}

    public function handle(\App\Domain\Intelligence\Services\SweepAccountResearchDispatches $sweeper): void
    {
        $sweeper->handle(workspaceId: $this->workspaceId);
    }
}
