<?php

namespace App\Jobs\Intelligence;

use App\Domain\Intelligence\Services\RunAccountResearchStage as RunAccountResearchStageService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/** ID-only, uniquely leased worker for one Account research stage. */
final class RunAccountResearchStage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public int $uniqueFor;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $researchRunId,
        public readonly string $stageId,
    ) {
        $this->onQueue((string) config('intelligence.queue', 'ai'));
        $this->timeout = max(60, (int) config('intelligence.research.timeout_seconds', 900));
        $this->uniqueFor = $this->timeout + 60;
    }

    public function uniqueId(): string
    {
        return 'account-research-stage:'.$this->workspaceId.':'.$this->researchRunId.':'.$this->stageId;
    }

    public function backoff(): int
    {
        return max(1, (int) config('intelligence.research.backoff_seconds', 30));
    }

    public function handle(RunAccountResearchStageService $stage): void
    {
        $stage->handle($this->workspaceId, $this->researchRunId, $this->stageId);
    }

    public function failed(?Throwable $exception): void
    {
        app(RunAccountResearchStageService::class)->failById(
            $this->workspaceId,
            $this->researchRunId,
            $this->stageId,
            $exception instanceof TimeoutExceededException ? 'research_timed_out' : 'research_failed',
        );
    }
}
