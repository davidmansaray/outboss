<?php

namespace App\Jobs\Objectives;

use App\Domain\Objectives\Services\AssessObjectiveAttribution as AssessObjectiveAttributionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class AssessObjectiveAttribution implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $conversionEventId,
    ) {
        $this->onQueue((string) config('objectives.queue', 'objectives'));
    }

    public function uniqueId(): string
    {
        return "objective-attribution:{$this->workspaceId}:{$this->conversionEventId}";
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(AssessObjectiveAttributionService $service): void
    {
        $service->handle($this->workspaceId, $this->conversionEventId);
    }
}
