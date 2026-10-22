<?php

namespace App\Jobs\Prospecting;

use App\Domain\Prospecting\Enums\SourceRunState;
use App\Models\SourceRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Find expired source-run leases and enqueue bounded recovery jobs.
 *
 * The sweep itself is queued so scheduler work stays short.  Recovery jobs
 * perform the locked state transition and re-check ownership, making a stale
 * list safe when another worker wins the race first.
 */
class SweepStalledSourceRuns implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly ?int $workspaceId = null,
        public readonly int $limit = 100,
    ) {
        $this->onQueue((string) config('prospecting.queue', 'low'));
    }

    public function uniqueId(): string
    {
        return 'prospecting-sweep-stalled-runs:'.($this->workspaceId === null ? 'all' : $this->workspaceId);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(): void
    {
        $limit = min(1000, max(1, $this->limit));
        $now = now();
        $states = [
            SourceRunState::Draft->value,
            SourceRunState::Previewed->value,
            SourceRunState::Queued->value,
            SourceRunState::Running->value,
            SourceRunState::Partial->value,
            SourceRunState::ReconciliationPending->value,
        ];

        $query = SourceRun::query()
            ->whereIn('state', $states)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('retry_after_at')->orWhere('retry_after_at', '<=', $now);
            })
            ->whereHas('source', function ($query): void {
                if ($this->workspaceId !== null) {
                    $query->where('workspace_id', $this->workspaceId);
                }
            })
            ->when($this->workspaceId !== null, fn ($query) => $query->where('workspace_id', $this->workspaceId))
            ->orderBy('id')
            ->limit($limit);

        $query->get(['id', 'workspace_id'])->each(function (SourceRun $run): void {
            RecoverSourceRun::dispatch((int) $run->workspace_id, (string) $run->id);
        });
    }
}
