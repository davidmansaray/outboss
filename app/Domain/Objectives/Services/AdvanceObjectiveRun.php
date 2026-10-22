<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\ObjectiveRunDispatchState;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseName;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseState;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Jobs\Objectives\PublishObjectiveRunDispatches;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunDispatch;
use App\Models\ObjectiveRunPhase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdvanceObjectiveRun
{
    public function succeeded(ObjectiveRun $run, ObjectiveRunPhase $phase, int $fence, string $outputHash): ObjectiveRun
    {
        return DB::transaction(function () use ($run, $phase, $fence, $outputHash): ObjectiveRun {
            $locked = $this->transitionOwnedPhase($run, $phase, $fence, [
                'state' => ObjectiveRunPhaseState::Succeeded->value,
                'output_hash' => $outputHash,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            if ($locked === null) {
                return $run->fresh(['phases', 'executionPlans']) ?? $run;
            }
            $next = $this->next($phase->phase);
            if ($next === null) {
                $locked->forceFill([
                    'state' => ObjectiveRunState::Completed,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => now(),
                    'finished_at' => now(),
                ])->save();

                return $locked->fresh();
            }
            $locked->forceFill([
                'current_phase' => $next,
                'state' => $next === ObjectiveRunPhaseName::AwaitApproval
                    ? ObjectiveRunState::Waiting
                    : ObjectiveRunState::Running,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => now(),
            ])->save();
            if ($next === ObjectiveRunPhaseName::AwaitApproval) {
                ObjectiveRunPhase::query()->firstOrCreate([
                    'workspace_id' => $locked->workspace_id,
                    'objective_run_id' => $locked->id,
                    'phase' => $next->value,
                    'attempt_number' => 1,
                ], [
                    'id' => (string) Str::uuid(),
                    'state' => ObjectiveRunPhaseState::Waiting,
                    'input_hash' => $outputHash,
                    'fencing_token' => 0,
                    'queued_at' => now(),
                ]);

                return $locked->fresh(['phases', 'executionPlans']);
            }
            $this->queueNext($locked, $next, $outputHash);

            return $locked->fresh(['phases', 'executionPlans']);
        }, 3);
    }

    public function waiting(ObjectiveRun $run, ObjectiveRunPhase $phase, int $fence, string $outputHash, string $code): ObjectiveRun
    {
        return $this->stop($run, $phase, $fence, ObjectiveRunState::Waiting, ObjectiveRunPhaseState::Waiting, $outputHash, $code);
    }

    public function needsReview(ObjectiveRun $run, ObjectiveRunPhase $phase, int $fence, string $outputHash, string $code): ObjectiveRun
    {
        return $this->stop($run, $phase, $fence, ObjectiveRunState::NeedsReview, ObjectiveRunPhaseState::Failed, $outputHash, $code);
    }

    public function failed(ObjectiveRun $run, ObjectiveRunPhase $phase, int $fence, string $code): ObjectiveRun
    {
        return $this->stop($run, $phase, $fence, ObjectiveRunState::Failed, ObjectiveRunPhaseState::Failed, null, $code);
    }

    private function stop(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        int $fence,
        ObjectiveRunState $runState,
        ObjectiveRunPhaseState $phaseState,
        ?string $outputHash,
        string $code,
    ): ObjectiveRun {
        return DB::transaction(function () use ($run, $phase, $fence, $runState, $phaseState, $outputHash, $code): ObjectiveRun {
            $locked = $this->transitionOwnedPhase($run, $phase, $fence, [
                'state' => $phaseState->value,
                'output_hash' => $outputHash,
                'safe_error_code' => $code,
                'safe_error_detail' => $runState === ObjectiveRunState::Waiting
                    ? 'The phase is waiting for a durable provider outcome.'
                    : 'The phase requires review before any further work.',
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => $runState === ObjectiveRunState::Waiting ? null : now(),
                'updated_at' => now(),
            ]);
            if ($locked === null) {
                return $run->fresh(['phases', 'armDrafts', 'executionPlans']) ?? $run;
            }
            $locked->forceFill([
                'state' => $runState,
                'safe_error_code' => $code,
                'safe_error_detail' => $runState === ObjectiveRunState::Waiting
                    ? 'Waiting for provider reconciliation.'
                    : 'Review the phase result before continuing.',
                'lease_owner' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => now(),
                'finished_at' => $runState === ObjectiveRunState::Failed ? now() : null,
            ])->save();

            return $locked->fresh(['phases', 'armDrafts', 'executionPlans']);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transitionOwnedPhase(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        int $fence,
        array $attributes,
    ): ?ObjectiveRun {
        $leaseOwner = $phase->lease_owner;
        if (! is_string($leaseOwner) || $leaseOwner === '') {
            return null;
        }
        $now = Carbon::now();
        $locked = ObjectiveRun::query()->where('workspace_id', $run->workspace_id)
            ->whereKey($run->id)
            ->where('state', ObjectiveRunState::Running->value)
            ->where('current_phase', $phase->phase->value)
            ->where('fencing_token', $fence)
            ->where('lease_owner', $leaseOwner)
            ->where('lease_expires_at', '>', $now)
            ->lockForUpdate()
            ->first();
        if ($locked === null) {
            return null;
        }
        $updated = ObjectiveRunPhase::query()
            ->where('workspace_id', $run->workspace_id)
            ->where('objective_run_id', $run->id)
            ->whereKey($phase->id)
            ->where('phase', $phase->phase->value)
            ->where('fencing_token', $fence)
            ->where('lease_owner', $leaseOwner)
            ->where('lease_expires_at', '>', $now)
            ->where('state', ObjectiveRunPhaseState::Running->value)
            ->update($attributes);

        return $updated === 1 ? $locked : null;
    }

    private function queueNext(ObjectiveRun $run, ObjectiveRunPhaseName $next, string $inputHash): void
    {
        $phase = ObjectiveRunPhase::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $run->workspace_id,
            'objective_run_id' => $run->id,
            'phase' => $next,
            'attempt_number' => 1,
            'state' => ObjectiveRunPhaseState::Queued,
            'input_hash' => $inputHash,
            'fencing_token' => 0,
            'queued_at' => now(),
        ]);
        $dispatch = ObjectiveRunDispatch::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $run->workspace_id,
            'objective_run_id' => $run->id,
            'phase' => $next,
            'input_hash' => $phase->input_hash,
            'state' => ObjectiveRunDispatchState::Pending,
            'attempts' => 0,
            'available_at' => now(),
        ]);
        DB::afterCommit(fn () => PublishObjectiveRunDispatches::dispatch((string) $dispatch->id));
    }

    private function next(ObjectiveRunPhaseName $phase): ?ObjectiveRunPhaseName
    {
        return match ($phase) {
            ObjectiveRunPhaseName::ValidateInputs => ObjectiveRunPhaseName::RecheckExclusions,
            ObjectiveRunPhaseName::RecheckExclusions => ObjectiveRunPhaseName::FreezeAssignments,
            ObjectiveRunPhaseName::FreezeAssignments => ObjectiveRunPhaseName::DraftMessages,
            ObjectiveRunPhaseName::DraftMessages => ObjectiveRunPhaseName::ValidatePlan,
            ObjectiveRunPhaseName::ValidatePlan => ObjectiveRunPhaseName::AwaitApproval,
            ObjectiveRunPhaseName::AwaitApproval => ObjectiveRunPhaseName::CompileCampaign,
            ObjectiveRunPhaseName::CompileCampaign => ObjectiveRunPhaseName::Launch,
            ObjectiveRunPhaseName::Launch => ObjectiveRunPhaseName::Execute,
            ObjectiveRunPhaseName::Execute => ObjectiveRunPhaseName::Observe,
            ObjectiveRunPhaseName::Observe => ObjectiveRunPhaseName::Attribute,
            ObjectiveRunPhaseName::Attribute => null,
        };
    }
}
