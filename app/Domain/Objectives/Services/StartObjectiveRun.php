<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Objectives\Enums\ObjectiveRunArmDraftState;
use App\Domain\Objectives\Enums\ObjectiveRunDispatchState;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseName;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseState;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Domain\Objectives\Exceptions\ObjectiveRunConflict;
use App\Jobs\Objectives\PublishObjectiveRunDispatches;
use App\Models\AiTaskSetting;
use App\Models\GtmExperiment;
use App\Models\Objective;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use App\Models\ObjectiveRunDispatch;
use App\Models\ObjectiveRunPhase;
use App\Models\OutreachPolicy;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StartObjectiveRun
{
    public function __construct(
        private readonly ObjectiveRunPreflight $preflight,
        private readonly BuildObjectiveDraftPrompt $prompts,
        private readonly AiBudgetGateway $budget,
        private readonly AiWorkspaceSettings $aiSettings,
    ) {}

    public function handle(Objective $objective, GtmExperiment $experiment, User $actor, string $idempotencyKey): ObjectiveRun
    {
        return $this->start($objective, $experiment, $actor, $idempotencyKey);
    }

    /**
     * Create a replacement before its predecessor is made terminal.
     *
     * Unlike a normal start, an idempotency key already bound to any run is
     * never reusable here. SupersedeObjectiveRun handles the sole valid replay:
     * the exact successor already linked from the predecessor.
     */
    public function handleForSupersession(
        Objective $objective,
        GtmExperiment $experiment,
        User $actor,
        string $idempotencyKey,
        ObjectiveRun $predecessor,
    ): ObjectiveRun {
        return $this->start($objective, $experiment, $actor, $idempotencyKey, $predecessor);
    }

    private function start(
        Objective $objective,
        GtmExperiment $experiment,
        User $actor,
        string $idempotencyKey,
        ?ObjectiveRun $predecessor = null,
    ): ObjectiveRun {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 160) {
            throw ValidationException::withMessages(['idempotency_key' => 'A bounded idempotency key is required.']);
        }
        $workspace = Workspace::query()->whereKey($objective->workspace_id)->firstOrFail();
        if (! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can start an Objective run.');
        }
        $this->aiSettings->ensureDefaults($workspace);
        $validatorVersion = (string) config('ai.tasks.message_drafting.validator_version');
        if ($validatorVersion === '' || ! hash_equals(ValidateDraftedMessage::VERSION, $validatorVersion)) {
            throw ValidationException::withMessages([
                'message_validator_version' => 'The active message validator configuration is unavailable or unsupported.',
            ]);
        }
        $keyHash = hash('sha256', 'workspace:'.$workspace->id.'|'.$idempotencyKey);

        return DB::transaction(function () use ($objective, $experiment, $actor, $workspace, $keyHash, $validatorVersion, $predecessor): ObjectiveRun {
            $lockedObjective = Objective::query()->where('workspace_id', $workspace->id)
                ->whereKey($objective->id)->lockForUpdate()->firstOrFail();
            $lockedPredecessor = $predecessor === null
                ? null
                : ObjectiveRun::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('objective_id', $lockedObjective->id)
                    ->whereKey($predecessor->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            $lockedExperiment = GtmExperiment::query()->where('workspace_id', $workspace->id)
                ->whereKey($experiment->id)->lockForUpdate()->firstOrFail();
            $task = AiTaskSetting::query()->where('workspace_id', $workspace->id)
                ->where('task', AiTask::MessageDrafting->value)->lockForUpdate()->firstOrFail();
            // The policy pointer determines the workflow envelope. Hold it
            // stable from preflight through run and envelope persistence.
            OutreachPolicy::query()->where('workspace_id', $workspace->id)
                ->lockForUpdate()->first();
            $assessment = $this->preflight->handle($lockedObjective, $lockedExperiment);
            $existing = ObjectiveRun::query()->where('workspace_id', $workspace->id)
                ->where('idempotency_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($lockedPredecessor !== null) {
                    throw new ObjectiveRunConflict('This Objective run key is already bound and cannot identify a new successor.');
                }
                $existingEnvelope = $existing->budgetEnvelope()
                    ->where('workspace_id', $workspace->id)
                    ->where('kind', 'objective_run')
                    ->where('owner_key', $existing->id)
                    ->first();
                if (! hash_equals((string) $existing->input_hash, $assessment->inputHash)
                    || (int) $existing->maximum_cost_micros !== $assessment->effectiveMaximumCostMicros
                    || $existingEnvelope === null
                    || (int) $existingEnvelope->hard_limit_micros !== $assessment->effectiveMaximumCostMicros) {
                    throw new ObjectiveRunConflict('This Objective run key was already used with different immutable inputs.');
                }

                return $existing->fresh(['phases', 'armDrafts', 'executionPlans']);
            }
            if (! $assessment->ready()) {
                throw ValidationException::withMessages([
                    'preflight' => array_map(fn (array $blocker): string => $blocker['code'], $assessment->blockers),
                ]);
            }
            $active = ObjectiveRun::query()->where('workspace_id', $workspace->id)
                ->where('objective_id', $lockedObjective->id)
                ->whereIn('state', [
                    ObjectiveRunState::Planned->value,
                    ObjectiveRunState::Running->value,
                    ObjectiveRunState::Waiting->value,
                    ObjectiveRunState::NeedsReview->value,
                    ObjectiveRunState::Paused->value,
                ])
                ->when(
                    $lockedPredecessor !== null,
                    fn ($query) => $query->whereKeyNot($lockedPredecessor->id),
                )
                ->lockForUpdate()
                ->first();
            if ($active !== null) {
                throw new ObjectiveRunConflict('This Objective already has a nonterminal run. Cancel or supersede it first.');
            }
            $objectiveVersion = $lockedObjective->currentVersion()->firstOrFail();
            $run = ObjectiveRun::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'objective_id' => $lockedObjective->id,
                'objective_version_id' => $objectiveVersion->id,
                'audience_snapshot_id' => $objectiveVersion->audience_snapshot_id,
                'gtm_experiment_id' => $lockedExperiment->id,
                'run_number' => ((int) ObjectiveRun::query()->where('workspace_id', $workspace->id)
                    ->where('objective_id', $lockedObjective->id)->max('run_number')) + 1,
                'state' => ObjectiveRunState::Planned,
                'current_phase' => ObjectiveRunPhaseName::ValidateInputs,
                'input_hash' => $assessment->inputHash,
                'idempotency_key_hash' => $keyHash,
                'schema_version' => 'objective_run_v1',
                'message_validator_version' => $validatorVersion,
                'maximum_cost_micros' => $assessment->effectiveMaximumCostMicros,
                'reserved_cost_micros' => 0,
                'actual_cost_micros' => 0,
                'fencing_token' => 0,
                'created_by_user_id' => $actor->id,
            ]);
            $envelope = $this->budget->openEnvelope(
                $workspace,
                $actor,
                'objective_run',
                (string) $run->id,
                (int) $run->maximum_cost_micros,
            );
            $run->forceFill(['ai_budget_envelope_id' => $envelope->id])->save();
            foreach ($lockedExperiment->arms()->orderBy('key')->get() as $arm) {
                $prompt = $this->prompts->handle($arm);
                $budgetRun = $this->budget->openRun($workspace, $actor, AiTask::MessageDrafting, $envelope);
                ObjectiveRunArmDraft::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'objective_run_id' => $run->id,
                    'gtm_experiment_arm_id' => $arm->id,
                    'ai_budget_run_id' => $budgetRun->id,
                    'state' => ObjectiveRunArmDraftState::Planned,
                    'prompt_version' => BuildObjectiveDraftPrompt::VERSION,
                    'schema_version' => (string) config('ai.tasks.message_drafting.response_schema.version'),
                    'validator_version' => $validatorVersion,
                    'model_id' => $task->model_id,
                    'task_policy_version' => $task->policy_version,
                    'input_hash' => $prompt['input_hash'],
                ]);
            }
            $phase = ObjectiveRunPhase::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'objective_run_id' => $run->id,
                'phase' => ObjectiveRunPhaseName::ValidateInputs,
                'attempt_number' => 1,
                'state' => ObjectiveRunPhaseState::Queued,
                'input_hash' => $assessment->inputHash,
                'fencing_token' => 0,
                'queued_at' => now(),
            ]);
            $dispatch = ObjectiveRunDispatch::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'objective_run_id' => $run->id,
                'phase' => ObjectiveRunPhaseName::ValidateInputs,
                'input_hash' => $phase->input_hash,
                'state' => ObjectiveRunDispatchState::Pending,
                'attempts' => 0,
                'available_at' => now(),
            ]);
            DB::afterCommit(fn () => PublishObjectiveRunDispatches::dispatch((string) $dispatch->id));

            return $run->fresh(['phases', 'armDrafts']);
        }, 3);
    }
}
