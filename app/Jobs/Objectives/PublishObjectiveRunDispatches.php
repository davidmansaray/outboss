<?php

namespace App\Jobs\Objectives;

use App\Domain\Objectives\Enums\ObjectiveRunDispatchState;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseState;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunDispatch;
use App\Models\ObjectiveRunPhase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PublishObjectiveRunDispatches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $dispatchId)
    {
        $this->onQueue((string) config('objectives.queue', 'objectives'));
    }

    public function handle(): void
    {
        $dispatch = ObjectiveRunDispatch::query()->whereKey($this->dispatchId)->first();
        if ($dispatch === null || $dispatch->state !== ObjectiveRunDispatchState::Pending || $dispatch->available_at->isFuture()) {
            return;
        }
        $phase = $dispatch->objectiveRun()->first()?->phases()
            ->where('phase', $dispatch->phase->value)
            ->where('input_hash', $dispatch->input_hash)
            ->where('state', 'queued')
            ->latest('attempt_number')->first();
        if ($phase === null) {
            $this->failDispatch('dispatch_phase_missing');

            return;
        }
        DB::transaction(function () use ($dispatch, $phase): void {
            $updated = ObjectiveRunDispatch::query()->whereKey($dispatch->id)
                ->where('state', ObjectiveRunDispatchState::Pending->value)
                ->update([
                    'state' => ObjectiveRunDispatchState::Published->value,
                    'published_at' => now(),
                    'attempts' => (int) $dispatch->attempts + 1,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                return;
            }
            DB::afterCommit(fn () => ExecuteObjectiveRunPhase::dispatch(
                (int) $dispatch->workspace_id,
                (string) $dispatch->objective_run_id,
                (string) $phase->id,
                (string) $dispatch->id,
            ));
        }, 3);
    }

    public function failed(?Throwable $exception): void
    {
        $this->failDispatch('dispatch_publish_failed');
    }

    private function failDispatch(string $code): void
    {
        DB::transaction(function () use ($code): void {
            $candidate = ObjectiveRunDispatch::query()->whereKey($this->dispatchId)->first();
            if ($candidate === null) {
                return;
            }

            $workspaceId = (int) $candidate->workspace_id;
            $runId = (string) $candidate->objective_run_id;
            $phaseName = $candidate->phase;
            $inputHash = (string) $candidate->input_hash;

            $run = ObjectiveRun::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($runId)
                ->whereIn('state', [ObjectiveRunState::Planned->value, ObjectiveRunState::Running->value])
                ->where('current_phase', $phaseName->value)
                ->lockForUpdate()
                ->first();
            if ($run === null) {
                return;
            }

            $phase = ObjectiveRunPhase::query()
                ->where('workspace_id', $workspaceId)
                ->where('objective_run_id', $runId)
                ->where('phase', $phaseName->value)
                ->where('input_hash', $inputHash)
                ->where('state', ObjectiveRunPhaseState::Queued->value)
                ->lockForUpdate()
                ->first();
            if ($phase === null) {
                return;
            }

            ObjectiveRunDispatch::query()
                ->where('workspace_id', $workspaceId)
                ->where('objective_run_id', $runId)
                ->whereKey($candidate->id)
                ->where('phase', $phaseName->value)
                ->where('input_hash', $inputHash)
                ->whereIn('state', [
                    ObjectiveRunDispatchState::Pending->value,
                    ObjectiveRunDispatchState::Published->value,
                ])
                ->update([
                    'state' => ObjectiveRunDispatchState::Pending->value,
                    'available_at' => now()->addSeconds(max(1, (int) config('objectives.dispatch_retry_seconds', 30))),
                    'published_at' => null,
                    'safe_error_code' => $code,
                    'safe_error_detail' => 'The durable Objective dispatch will be retried.',
                    'updated_at' => now(),
                ]);
        }, 3);
    }
}
