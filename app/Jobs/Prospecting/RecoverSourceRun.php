<?php

namespace App\Jobs\Prospecting;

use App\Domain\Prospecting\Services\RecoverStaleSourceRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Recover one expired source-run worker lease. */
final class RecoverSourceRun implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $runId,
    ) {
        $this->onQueue((string) config('prospecting.queue', 'low'));
    }

    public function uniqueId(): string
    {
        return 'prospecting-recover-run:'.$this->workspaceId.':'.$this->runId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(RecoverStaleSourceRun $recover): void
    {
        if ($this->workspaceId <= 0 || trim($this->runId) === '') {
            return;
        }

        $recover->handle($this->workspaceId, $this->runId);
    }
}
