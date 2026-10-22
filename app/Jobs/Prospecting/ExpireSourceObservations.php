<?php

namespace App\Jobs\Prospecting;

use App\Domain\Prospecting\Services\ExpireSourceObservations as ExpireSourceObservationsService;
use App\Models\ProspectSource;
use App\Models\Workspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queue the policy-aware observation expiry pass.
 *
 * Only numeric/UUID aggregate identifiers are serialised.  A null workspace
 * is supported for an operator-triggered global pass; scheduled dispatches
 * use one job per workspace so each execution remains explicitly scoped.
 */
final class ExpireSourceObservations implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly ?int $workspaceId = null,
        public readonly ?string $sourceId = null,
    ) {
        $this->onQueue((string) config('prospecting.queue', 'low'));
    }

    public function uniqueId(): string
    {
        return 'prospecting-expire-observations:'
            .($this->workspaceId === null ? 'all' : (string) $this->workspaceId)
            .':'.($this->sourceId ?? 'all');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ExpireSourceObservationsService $expire): void
    {
        $workspace = $this->workspace();
        if ($this->workspaceId !== null && $workspace === null) {
            return;
        }

        $source = null;
        if ($this->sourceId !== null) {
            // A source ID is never resolved without its workspace.  This is a
            // fail-closed guard against a malformed/manual queue payload.
            if ($workspace === null) {
                return;
            }
            $source = ProspectSource::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($this->sourceId)
                ->first();
            if ($source === null) {
                return;
            }
        }

        $expire->handle($workspace, $source);
    }

    private function workspace(): ?Workspace
    {
        if ($this->workspaceId === null) {
            return null;
        }

        return Workspace::query()->find($this->workspaceId);
    }
}
