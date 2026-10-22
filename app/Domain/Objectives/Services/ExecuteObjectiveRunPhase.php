<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Objectives\Enums\ExecutionPlanStatus;
use App\Domain\Objectives\Enums\GtmExperimentStatus;
use App\Domain\Objectives\Enums\ObjectiveRunDispatchState;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseName;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseState;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\AiProviderOperation;
use App\Models\AudienceSnapshotMember;
use App\Models\GtmExperimentAssignmentMember;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunDispatch;
use App\Models\ObjectiveRunPhase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Claim and execute exactly one durable Objective run phase. */
final class ExecuteObjectiveRunPhase
{
    public function __construct(
        private readonly ObjectiveRunPreflight $preflight,
        private readonly ObjectiveReadiness $readiness,
        private readonly DraftObjectiveMessages $drafts,
        private readonly ValidateExecutionPlan $plans,
        private readonly AdvanceObjectiveRun $advance,
    ) {}

    public function handle(int $workspaceId, string $runId, string $phaseId, string $dispatchId): void
    {
        $claimed = $this->claim($workspaceId, $runId, $phaseId, $dispatchId);
        if ($claimed === null) {
            return;
        }
        [$run, $phase, $dispatch, $fence] = $claimed;

        try {
            match ($phase->phase) {
                ObjectiveRunPhaseName::ValidateInputs => $this->validateInputs($run, $phase, $fence),
                ObjectiveRunPhaseName::RecheckExclusions => $this->recheckExclusions($run, $phase, $fence),
                ObjectiveRunPhaseName::FreezeAssignments => $this->verifyAssignments($run, $phase, $fence),
                ObjectiveRunPhaseName::DraftMessages => $this->draftMessages($run, $phase, $dispatch, $fence),
                ObjectiveRunPhaseName::ValidatePlan => $this->validatePlan($run, $phase, $dispatch, $fence),
                default => $this->advance->needsReview($run, $phase, $fence, $phase->input_hash, 'phase_not_implemented'),
            };
        } catch (ValidationException|ModelNotFoundException) {
            $this->advance->needsReview($run, $phase, $fence, (string) $phase->input_hash, 'objective_inputs_changed');
        } catch (Throwable $exception) {
            report($exception);
            $this->failConservatively($run, $phase, $fence, 'objective_phase_failed');
        }
    }

    public function failById(int $workspaceId, string $runId, string $phaseId, string $code): void
    {
        $run = ObjectiveRun::query()->where('workspace_id', $workspaceId)->where('origin', 'ai')->whereKey($runId)->first();
        $phase = ObjectiveRunPhase::query()->where('workspace_id', $workspaceId)
            ->where('objective_run_id', $runId)->whereKey($phaseId)->first();
        if ($run === null || $phase === null || $phase->state !== ObjectiveRunPhaseState::Running) {
            return;
        }
        $this->failConservatively($run, $phase, (int) $phase->fencing_token, $code);
    }

    private function failConservatively(ObjectiveRun $run, ObjectiveRunPhase $phase, int $fence, string $code): void
    {
        // A runtime/DB failure after the paid boundary is not permission to
        // start a replacement run. Inspect durable operations, not the lost
        // worker's in-memory result. If this read fails, leave recovery in charge.
        $operations = AiProviderOperation::query()
            ->where('workspace_id', $run->workspace_id)
            ->whereIn('budget_run_id', $run->armDrafts()->select('ai_budget_run_id'))
            ->where(function ($query): void {
                $query->whereNotNull('started_at')->orWhereIn('status', [
                    AiOperationStatus::Executing->value, AiOperationStatus::Uncertain->value,
                    AiOperationStatus::Reconciling->value, AiOperationStatus::Succeeded->value,
                    AiOperationStatus::Reconciled->value, AiOperationStatus::ManualReview->value,
                ]);
            })->get();
        if ($operations->isEmpty()) {
            $this->advance->failed($run, $phase, $fence, $code);

            return;
        }
        if ($operations->contains(fn (AiProviderOperation $operation): bool => in_array($operation->status, [
            AiOperationStatus::Executing, AiOperationStatus::Uncertain, AiOperationStatus::Reconciling,
        ], true))) {
            $this->advance->waiting($run, $phase, $fence, (string) $phase->input_hash, 'provider_reconciliation_pending');

            return;
        }
        $missingResult = $run->armDrafts()->whereIn('ai_budget_run_id', $operations->pluck('budget_run_id'))
            ->whereNull('output_hash')->exists();
        $this->advance->needsReview($run, $phase, $fence, (string) $phase->input_hash,
            $missingResult ? 'paid_result_lost' : 'objective_phase_failed_after_provider');
    }

    /** @return array{ObjectiveRun,ObjectiveRunPhase,ObjectiveRunDispatch,int}|null */
    private function claim(int $workspaceId, string $runId, string $phaseId, string $dispatchId): ?array
    {
        return DB::transaction(function () use ($workspaceId, $runId, $phaseId, $dispatchId): ?array {
            $run = ObjectiveRun::query()->where('workspace_id', $workspaceId)->where('origin', 'ai')->whereKey($runId)
                ->lockForUpdate()->first();
            $phase = ObjectiveRunPhase::query()->where('workspace_id', $workspaceId)
                ->where('objective_run_id', $runId)->whereKey($phaseId)->lockForUpdate()->first();
            $dispatch = ObjectiveRunDispatch::query()->where('workspace_id', $workspaceId)
                ->where('objective_run_id', $runId)->whereKey($dispatchId)->lockForUpdate()->first();
            if ($run === null || $phase === null || $dispatch === null
                || ! in_array($run->state, [ObjectiveRunState::Planned, ObjectiveRunState::Running], true)
                || $run->current_phase !== $phase->phase
                || $phase->state !== ObjectiveRunPhaseState::Queued
                || ! in_array($dispatch->state, [ObjectiveRunDispatchState::Pending, ObjectiveRunDispatchState::Published], true)
                || ! hash_equals((string) $phase->input_hash, (string) $dispatch->input_hash)) {
                return null;
            }
            $lease = (string) Str::uuid();
            $fence = (int) $run->fencing_token + 1;
            $expires = now()->addSeconds(max(60, (int) config('objectives.phase_lease_seconds', 600)));
            $run->forceFill([
                'state' => ObjectiveRunState::Running,
                'started_at' => $run->started_at ?? now(),
                'lease_owner' => $lease,
                'lease_expires_at' => $expires,
                'fencing_token' => $fence,
                'heartbeat_at' => now(),
                'safe_error_code' => null,
                'safe_error_detail' => null,
            ])->save();
            $phase->forceFill([
                'state' => ObjectiveRunPhaseState::Running,
                'lease_owner' => $lease,
                'lease_expires_at' => $expires,
                'fencing_token' => $fence,
                'started_at' => now(),
                'safe_error_code' => null,
                'safe_error_detail' => null,
            ])->save();
            $dispatch->forceFill([
                'state' => ObjectiveRunDispatchState::Consumed,
                'attempts' => (int) $dispatch->attempts + 1,
                'published_at' => $dispatch->published_at ?? now(),
                'consumed_at' => now(),
            ])->save();

            return [$run->fresh(), $phase->fresh(), $dispatch->fresh(), $fence];
        }, 3);
    }

    private function validateInputs(ObjectiveRun $run, ObjectiveRunPhase $phase, int $fence): void
    {
        $result = $this->preflight->handle($run->objective()->firstOrFail(), $run->experiment()->firstOrFail());
        if (! $result->ready() || ! hash_equals((string) $run->input_hash, $result->inputHash)) {
            $this->advance->needsReview($run, $phase, $fence, $result->inputHash, 'objective_inputs_changed');

            return;
        }
        $this->advance->succeeded($run, $phase, $fence, $result->inputHash);
    }

    private function recheckExclusions(ObjectiveRun $run, ObjectiveRunPhase $phase, int $fence): void
    {
        $result = $this->readiness->handle($run->objective()->firstOrFail());
        $hash = CanonicalObjectivePayload::hash([
            'readiness_hash' => $result->inputHash,
            'blockers' => $result->blockers,
        ]);
        if (! $result->ready()) {
            $this->advance->needsReview($run, $phase, $fence, $hash, 'objective_readiness_changed');

            return;
        }
        $this->advance->succeeded($run, $phase, $fence, $hash);
    }

    private function verifyAssignments(ObjectiveRun $run, ObjectiveRunPhase $phase, int $fence): void
    {
        $experiment = $run->experiment()->firstOrFail();
        $included = AudienceSnapshotMember::query()->where('workspace_id', $run->workspace_id)
            ->where('audience_snapshot_id', $run->audience_snapshot_id)->where('result', 'included')->count();
        $assignmentIds = $experiment->assignments()->pluck('id');
        $assigned = GtmExperimentAssignmentMember::query()->where('workspace_id', $run->workspace_id)
            ->whereIn('gtm_experiment_assignment_id', $assignmentIds)->count();
        $hash = CanonicalObjectivePayload::hash([
            'experiment_id' => (string) $experiment->id,
            'state' => $experiment->status,
            'assignment_seed_hash' => (string) $experiment->assignment_seed_hash,
            'included' => $included,
            'assigned' => $assigned,
        ]);
        if ($experiment->status !== GtmExperimentStatus::Assigned || $included < 1 || $assigned !== $included) {
            $this->advance->needsReview($run, $phase, $fence, $hash, 'assignment_coverage_mismatch');

            return;
        }
        $this->advance->succeeded($run, $phase, $fence, $hash);
    }

    private function draftMessages(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        int $fence,
    ): void {
        $result = $this->drafts->handle($run, $phase, $dispatch, $fence);
        $stillOwned = ObjectiveRun::query()->where('workspace_id', $run->workspace_id)
            ->whereKey($run->id)
            ->where('state', ObjectiveRunState::Running->value)
            ->where('current_phase', ObjectiveRunPhaseName::DraftMessages->value)
            ->where('fencing_token', $fence)
            ->exists();
        if (! $stillOwned) {
            return;
        }
        match ($result->runState) {
            ObjectiveRunState::Waiting => $this->advance->waiting(
                $run,
                $phase,
                $fence,
                $result->outputHash,
                $result->waitingReason ?? 'provider_reconciliation_pending',
            ),
            ObjectiveRunState::NeedsReview => $this->advance->needsReview($run, $phase, $fence, $result->outputHash, 'draft_requires_review'),
            ObjectiveRunState::Failed => $this->advance->failed($run, $phase, $fence, 'draft_failed'),
            ObjectiveRunState::Cancelled, ObjectiveRunState::Superseded => null,
            default => $this->advance->succeeded($run, $phase, $fence, $result->outputHash),
        };
    }

    private function validatePlan(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        int $fence,
    ): void {
        $plan = $this->plans->handle($run, $phase, $dispatch, $fence);
        if ($plan === null) {
            return;
        }
        if ($plan->status === ExecutionPlanStatus::Partial) {
            $this->advance->needsReview($run, $phase, $fence, (string) $plan->plan_hash, 'plan_partial');

            return;
        }
        $this->advance->succeeded($run, $phase, $fence, (string) $plan->plan_hash);
    }
}
