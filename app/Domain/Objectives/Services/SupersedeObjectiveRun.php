<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\ExecutionPlanStatus;
use App\Domain\Objectives\Enums\ObjectiveRunArmDraftState;
use App\Domain\Objectives\Enums\ObjectiveRunDispatchState;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseState;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Models\GtmExperiment;
use App\Models\Objective;
use App\Models\ObjectiveRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupersedeObjectiveRun
{
    public function __construct(
        private readonly StartObjectiveRun $start,
        private readonly FinalizeObjectiveRunBudget $finalizeBudget,
    ) {}

    public function handle(ObjectiveRun $run, GtmExperiment $experiment, User $actor, string $idempotencyKey, string $reason): ObjectiveRun
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 160) {
            throw ValidationException::withMessages(['idempotency_key' => 'A bounded idempotency key is required.']);
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'A bounded supersession reason is required.']);
        }

        return DB::transaction(function () use ($run, $experiment, $actor, $idempotencyKey, $reason): ObjectiveRun {
            // Match StartObjectiveRun's lock order so a normal start and a
            // supersession serialize on the Objective before either run row.
            $objective = Objective::query()
                ->where('workspace_id', $run->workspace_id)
                ->whereKey($run->objective_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = ObjectiveRun::query()->where('workspace_id', $run->workspace_id)
                ->where('objective_id', $objective->id)
                ->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $workspace = Workspace::query()->whereKey($locked->workspace_id)->firstOrFail();
            if (! $workspace->isOwner($actor)) {
                throw new AuthorizationException('Only the workspace owner can supersede an Objective run.');
            }
            $expectedHash = hash('sha256', 'workspace:'.$workspace->id.'|'.$idempotencyKey);
            if ($locked->state === ObjectiveRunState::Superseded) {
                $successor = $locked->superseded_by_run_id === null
                    ? null
                    : ObjectiveRun::query()
                        ->where('workspace_id', $workspace->id)
                        ->whereKey($locked->superseded_by_run_id)
                        ->first();
                if ($successor instanceof ObjectiveRun
                    && $this->isLinkedSuccessor($locked, $successor, $experiment, $expectedHash)) {
                    return $successor->fresh(['phases', 'armDrafts', 'executionPlans']);
                }

                throw ValidationException::withMessages([
                    'idempotency_key' => 'This Objective run was superseded with a different idempotency key.',
                ]);
            }
            if (in_array($locked->state, [ObjectiveRunState::Completed, ObjectiveRunState::Cancelled], true)) {
                throw ValidationException::withMessages(['run' => 'This Objective run can no longer be superseded.']);
            }
            if ($locked->superseded_by_run_id !== null) {
                throw ValidationException::withMessages([
                    'run' => 'This Objective run has an invalid successor linkage and cannot be superseded safely.',
                ]);
            }
            if ($objective->current_plan_id !== null
                && ! $locked->executionPlans()->whereKey($objective->current_plan_id)->exists()) {
                throw ValidationException::withMessages([
                    'plan' => 'The Objective current plan does not belong to the run being superseded.',
                ]);
            }

            // Bind the key to a distinct, fully initialized replacement before
            // changing the predecessor, its plan pointer, or its AI accounting.
            $successor = $this->start->handleForSupersession(
                $objective,
                $experiment,
                $actor,
                $idempotencyKey,
                $locked,
            );
            if (! $this->isNewSuccessor($locked, $successor, $objective, $experiment, $expectedHash)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'The idempotency key did not create a valid successor for this Objective run.',
                ]);
            }

            $locked->phases()->whereIn('state', [
                ObjectiveRunPhaseState::Queued->value,
                ObjectiveRunPhaseState::Waiting->value,
                ObjectiveRunPhaseState::Running->value,
            ])->update([
                'state' => ObjectiveRunPhaseState::Cancelled->value,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $locked->dispatches()->whereIn('state', [ObjectiveRunDispatchState::Pending->value, ObjectiveRunDispatchState::Published->value])
                ->update(['state' => ObjectiveRunDispatchState::Cancelled->value, 'updated_at' => now()]);
            $locked->armDrafts()->where('state', ObjectiveRunArmDraftState::Planned->value)->update([
                'state' => ObjectiveRunArmDraftState::Cancelled->value,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $locked->executionPlans()->whereIn('status', [ExecutionPlanStatus::Draft->value, ExecutionPlanStatus::AwaitingApproval->value, ExecutionPlanStatus::Partial->value])
                ->update(['status' => ExecutionPlanStatus::Superseded->value, 'superseded_at' => now(), 'updated_at' => now()]);
            if ($objective->current_plan_id !== null) {
                $objective->forceFill(['current_plan_id' => null])->save();
            }
            if (! $this->finalizeBudget->cancelUnsent(
                $locked,
                'objective_run_superseded',
                'The Objective run was superseded before completion.',
            )) {
                throw ValidationException::withMessages([
                    'run' => 'This Objective run still has provider work awaiting reconciliation and cannot be superseded safely.',
                ]);
            }
            $locked->forceFill([
                'state' => ObjectiveRunState::Superseded,
                'cancellation_reason_code' => 'owner_superseded',
                'cancellation_detail' => $reason,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'fencing_token' => (int) $locked->fencing_token + 1,
                'superseded_at' => now(),
                'finished_at' => now(),
                'superseded_by_run_id' => $successor->id,
            ])->save();

            return $successor;
        }, 3);
    }

    private function isLinkedSuccessor(
        ObjectiveRun $predecessor,
        ObjectiveRun $successor,
        GtmExperiment $experiment,
        string $expectedKeyHash,
    ): bool {
        return (string) $predecessor->superseded_by_run_id === (string) $successor->id
            && $this->hasSuccessorLineage($predecessor, $successor, $experiment)
            && hash_equals((string) $successor->idempotency_key_hash, $expectedKeyHash);
    }

    private function hasSuccessorLineage(
        ObjectiveRun $predecessor,
        ObjectiveRun $successor,
        GtmExperiment $experiment,
    ): bool {
        return (string) $successor->id !== (string) $predecessor->id
            && (int) $successor->workspace_id === (int) $predecessor->workspace_id
            && (string) $successor->objective_id === (string) $predecessor->objective_id
            && (string) $successor->gtm_experiment_id === (string) $experiment->id
            && (string) $successor->objective_version_id === (string) $experiment->objective_version_id
            && (string) $successor->audience_snapshot_id === (string) $experiment->audience_snapshot_id
            && (int) $successor->run_number > (int) $predecessor->run_number;
    }

    private function isNewSuccessor(
        ObjectiveRun $predecessor,
        ObjectiveRun $successor,
        Objective $objective,
        GtmExperiment $experiment,
        string $expectedKeyHash,
    ): bool {
        return $successor->state === ObjectiveRunState::Planned
            && $successor->superseded_by_run_id === null
            && (string) $successor->objective_version_id === (string) $objective->current_version_id
            && $this->hasSuccessorLineage($predecessor, $successor, $experiment)
            && hash_equals((string) $successor->idempotency_key_hash, $expectedKeyHash);
    }
}
