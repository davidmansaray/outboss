<?php

namespace App\Jobs\Objectives;

use App\Domain\Objectives\Services\RebuildObjectiveProgress as RebuildObjectiveProgressService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RebuildObjectiveProgress implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $objectiveVersionId,
    ) {
        $this->onQueue((string) config('objectives.queue', 'objectives'));
    }

    public function uniqueId(): string
    {
        return "objective-progress:{$this->workspaceId}:{$this->objectiveVersionId}";
    }

    public function backoff(): int
    {
        return 30;
    }

    public function handle(RebuildObjectiveProgressService $service): void
    {
        $service->handle($this->workspaceId, $this->objectiveVersionId);
    }
}
