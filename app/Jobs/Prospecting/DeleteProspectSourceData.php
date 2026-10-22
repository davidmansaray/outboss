<?php

namespace App\Jobs\Prospecting;

use App\Domain\Prospecting\Services\DeleteProspectSourceData as DeleteProspectSourceDataService;
use App\Models\ProspectSource;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Execute policy-aware source deletion outside the request cycle.
 *
 * The queue payload contains the owning workspace, source UUID, and the
 * requested canonical-delete upgrade. The durable request on the source row
 * is authoritative; the worker resolves it through both workspace and UUID
 * before invoking the domain service.
 */
final class DeleteProspectSourceData implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $sourceId,
        public readonly bool $deleteCanonical = false,
    ) {
        $this->onQueue((string) config('prospecting.queue', 'low'));
    }

    public function uniqueId(): string
    {
        // Canonical deletion is a monotonic upgrade of the same source
        // deletion. Both parameterizations must share one uniqueness key.
        return 'prospecting-delete-source:'.$this->workspaceId.':'.$this->sourceId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(DeleteProspectSourceDataService $delete): void
    {
        if ($this->workspaceId <= 0 || trim($this->sourceId) === '') {
            return;
        }

        $source = ProspectSource::query()
            ->where('workspace_id', $this->workspaceId)
            ->whereKey($this->sourceId)
            ->first();
        if ($source === null) {
            return;
        }

        $delete->handle($source, $this->deleteCanonical);
    }
}
