<?php

namespace App\Jobs\Intelligence;

use App\Domain\Intelligence\Services\ExpireResearchSourceSnapshots as ExpireResearchSourceSnapshotsService;
use App\Models\Workspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Queue one bounded, workspace-scoped S2 retention pass. */
final class ExpireResearchSourceSnapshots implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly ?int $workspaceId = null,
        public readonly ?int $limit = null,
    ) {
        $this->onQueue((string) config('intelligence.queue', 'ai'));
    }

    public function uniqueId(): string
    {
        return 'intelligence-expire-source-snapshots:'.($this->workspaceId === null ? 'all' : (string) $this->workspaceId);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ExpireResearchSourceSnapshotsService $expire): void
    {
        if ($this->workspaceId === null) {
            $expire->handle(null, $this->limit);

            return;
        }

        $workspace = Workspace::query()->find($this->workspaceId);
        if ($workspace === null) {
            return;
        }

        $expire->handle($workspace, $this->limit);
    }
}
