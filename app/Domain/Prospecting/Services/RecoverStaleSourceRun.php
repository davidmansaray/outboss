<?php

namespace App\Domain\Prospecting\Services;

use App\Domain\Prospecting\Enums\SourceRunState;
use App\Models\SourceRun;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Return a source run whose worker lease expired to the durable queue state.
 *
 * Source runs are the recovery boundary for imports.  The worker only carries
 * aggregate identifiers; the checkpoint and observations remain in the
 * database and are therefore available to a later retry.  Terminal runs and
 * runs with a live lease are deliberately left untouched.
 */
final class RecoverStaleSourceRun
{
    public function __construct(private readonly ReconcileSourceRunCounts $reconcileCounts) {}

    /**
     * Recover one run when it belongs to the supplied workspace and its lease
     * has expired.
     */
    public function handle(Workspace|int $workspace, string $runId): bool
    {
        $workspaceId = $workspace instanceof Workspace ? (int) $workspace->id : $workspace;
        if ($workspaceId <= 0 || trim($runId) === '') {
            return false;
        }

        $run = DB::transaction(function () use ($workspaceId, $runId): ?SourceRun {
            $locked = SourceRun::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($runId)
                ->whereHas('source', fn ($query) => $query->where('workspace_id', $workspaceId))
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $this->leaseExpired($locked)) {
                return null;
            }

            // Read the persisted scalar instead of relying on the model's
            // magic property. The cast is present at runtime, but Larastan
            // intentionally types an undeclared Eloquent attribute as a
            // string; normalising once also handles rows written before the
            // enum cast was introduced.
            $state = SourceRunState::tryFrom((string) $locked->getRawOriginal('state'));
            if ($state === null || $state->isTerminal()) {
                return null;
            }

            $locked->forceFill([
                'state' => SourceRunState::Queued,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'retry_after_at' => null,
                'finished_at' => null,
                'safe_error_code' => 'source_run_lease_expired',
                'safe_error_message' => 'The source worker lease expired; the run was queued for recovery.',
            ])->save();

            return $locked->fresh();
        }, 3);

        if ($run === null) {
            return false;
        }

        // Counters are projections of observations.  Rebuilding them after a
        // crash makes a resumed run report the durable state even when the
        // worker stopped between an observation write and counter update.
        $this->reconcileCounts->handle($run);

        return true;
    }

    public function recover(Workspace|int $workspace, string $runId): bool
    {
        return $this->handle($workspace, $runId);
    }

    private function leaseExpired(SourceRun $run): bool
    {
        $leaseExpiresAt = $run->getAttribute('lease_expires_at');

        return $leaseExpiresAt instanceof CarbonInterface && ! $leaseExpiresAt->isFuture();
    }
}
