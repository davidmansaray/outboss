<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\ExecutionPlanStatus;
use App\Domain\Objectives\Enums\ObjectiveRunArmDraftState;
use App\Domain\Objectives\Enums\ObjectiveRunDispatchState;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseState;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Models\ObjectiveRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelObjectiveRun
{
    public function __construct(private readonly FinalizeObjectiveRunBudget $finalizeBudget) {}

    public function handle(ObjectiveRun $run, User $actor, string $reason): ObjectiveRun
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'A bounded cancellation reason is required.']);
        }

        return DB::transaction(function () use ($run, $actor, $reason): ObjectiveRun {
            $locked = ObjectiveRun::query()->where('workspace_id', $run->workspace_id)
                ->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $workspace = Workspace::query()->whereKey($locked->workspace_id)->firstOrFail();
            if (! $workspace->isOwner($actor)) {
                throw new AuthorizationException('Only the workspace owner can cancel an Objective run.');
            }
            if (in_array($locked->state, [
                ObjectiveRunState::Completed,
                ObjectiveRunState::Partial,
                ObjectiveRunState::Failed,
                ObjectiveRunState::Cancelled,
                ObjectiveRunState::Superseded,
            ], true)) {
                return $locked->fresh(['phases', 'armDrafts', 'executionPlans']);
            }
            $locked->forceFill([
                'state' => ObjectiveRunState::Cancelled,
                'cancellation_reason_code' => 'owner_cancelled',
                'cancellation_detail' => $reason,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'fencing_token' => (int) $locked->fencing_token + 1,
                'cancelled_at' => now(),
                'finished_at' => now(),
            ])->save();
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
            $locked->dispatches()->whereIn('state', [
                ObjectiveRunDispatchState::Pending->value,
                ObjectiveRunDispatchState::Published->value,
            ])->update([
                'state' => ObjectiveRunDispatchState::Cancelled->value,
                'updated_at' => now(),
            ]);
            $locked->armDrafts()->where('state', ObjectiveRunArmDraftState::Planned->value)->update([
                'state' => ObjectiveRunArmDraftState::Cancelled->value,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $locked->executionPlans()->whereIn('status', [
                ExecutionPlanStatus::Draft->value,
                ExecutionPlanStatus::AwaitingApproval->value,
                ExecutionPlanStatus::Partial->value,
            ])->update([
                'status' => ExecutionPlanStatus::Cancelled->value,
                'updated_at' => now(),
            ]);
            $this->finalizeBudget->cancelUnsent(
                $locked,
                'objective_run_cancelled',
                'The Objective run was cancelled.',
            );

            return $locked->fresh(['phases', 'armDrafts', 'executionPlans']);
        }, 3);
    }
}
