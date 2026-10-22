<?php

namespace App\Jobs\Objectives;

use App\Domain\Objectives\Services\ExecuteObjectiveRunPhase as ExecuteObjectiveRunPhaseService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/** ID-only worker for one fenced Objective run phase. */
final class ExecuteObjectiveRunPhase implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public int $uniqueFor;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $objectiveRunId,
        public readonly string $phaseId,
        public readonly string $dispatchId,
    ) {
        $this->onQueue((string) config('objectives.queue', 'objectives'));
        $this->timeout = max(60, (int) config('objectives.phase_lease_seconds', 600));
        $this->uniqueFor = $this->timeout + 60;
    }

    public function uniqueId(): string
    {
        return implode(':', ['objective-run-phase', $this->workspaceId, $this->objectiveRunId, $this->phaseId, $this->dispatchId]);
    }

    public function backoff(): int
    {
        return max(1, (int) config('objectives.dispatch_retry_seconds', 30));
    }

    public function handle(ExecuteObjectiveRunPhaseService $service): void
    {
        $service->handle($this->workspaceId, $this->objectiveRunId, $this->phaseId, $this->dispatchId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ExecuteObjectiveRunPhaseService::class)->failById(
            $this->workspaceId,
            $this->objectiveRunId,
            $this->phaseId,
            $exception instanceof TimeoutExceededException ? 'objective_phase_timed_out' : 'objective_phase_failed',
        );
    }
}
