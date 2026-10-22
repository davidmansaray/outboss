<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Ai\Services\AiCostEstimator;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Objectives\DTOs\ObjectiveRunPreflightResult;
use App\Domain\Objectives\Enums\GtmExperimentStatus;
use App\Domain\Objectives\Enums\ObjectiveStatus;
use App\Domain\Objectives\Enums\OutreachPolicyStatus;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\AiBudgetSetting;
use App\Models\AiProviderCredential;
use App\Models\AiTaskSetting;
use App\Models\GtmExperiment;
use App\Models\Objective;
use App\Models\ObjectiveVersion;
use App\Models\OutreachPolicy;
use App\Models\Workspace;
use Throwable;

final class ObjectiveRunPreflight
{
    public const VERSION = 'objective_run_preflight_v1';

    public function __construct(
        private readonly ObjectiveReadiness $readiness,
        private readonly BuildObjectiveDraftPrompt $prompts,
        private readonly AiWorkspaceSettings $aiSettings,
        private readonly AiCostEstimator $costs,
        private readonly AiBudgetGateway $budgetGateway,
    ) {}

    public function handle(Objective $objective, GtmExperiment $experiment): ObjectiveRunPreflightResult
    {
        $objective = Objective::query()->where('workspace_id', $objective->workspace_id)
            ->whereKey($objective->id)->with('currentVersion')->firstOrFail();
        $workspace = Workspace::query()->whereKey($objective->workspace_id)->firstOrFail();
        $experiment = GtmExperiment::query()->where('workspace_id', $workspace->id)
            ->whereKey($experiment->id)->with('arms')->firstOrFail();
        $blockers = [];
        $warnings = [];
        $objectiveReadiness = $this->readiness->handle($objective);
        foreach ($objectiveReadiness->blockers as $blocker) {
            $blockers[] = $blocker;
        }
        foreach ($objectiveReadiness->warnings as $warning) {
            $warnings[] = $warning;
        }
        $version = $objective->currentVersion;
        if (! $version instanceof ObjectiveVersion || $objective->status !== ObjectiveStatus::Ready) {
            $this->block($blockers, 'objective_not_ready', 'Move the current Objective Version to ready before drafting a plan.');
        }
        if ($version instanceof ObjectiveVersion
            && ((string) $experiment->objective_id !== (string) $objective->id
                || (string) $experiment->objective_version_id !== (string) $version->id
                || (string) $experiment->audience_snapshot_id !== (string) $version->audience_snapshot_id
                || (string) $experiment->conversion_definition_version_id !== (string) $version->conversion_definition_version_id)) {
            $this->block($blockers, 'experiment_lineage_mismatch', 'Use the assigned experiment for this exact Objective Version.');
        }
        if ($experiment->status !== GtmExperimentStatus::Assigned || $experiment->assignment_seed_hash === null) {
            $this->block($blockers, 'experiment_not_assigned', 'Freeze the experiment assignments before starting a run.');
        }
        $assignments = $experiment->assignments()->withCount('members')->get();
        $assignedMembers = $assignments->sum('members_count');
        $expectedMembers = $version instanceof ObjectiveVersion
            ? $version->audienceSnapshot()->first()?->members()->where('result', 'included')->count() ?? 0
            : 0;
        if ($expectedMembers < 1 || $assignedMembers !== $expectedMembers) {
            $this->block($blockers, 'assignment_coverage_mismatch', 'Every included audience member must have exactly one frozen assignment.');
        }
        foreach ($experiment->arms as $arm) {
            if ($assignments->where('gtm_experiment_arm_id', $arm->id)->sum('members_count') < 1) {
                $this->block($blockers, 'experiment_arm_empty', 'Every arm needs an assigned audience member. Create a new experiment with a larger audience or one arm; frozen assignments are not rebalanced.');
            }
        }

        $this->aiSettings->ensureDefaults($workspace);
        $task = AiTaskSetting::query()->where('workspace_id', $workspace->id)
            ->where('task', AiTask::MessageDrafting->value)->first();
        $budget = AiBudgetSetting::query()->whereKey($workspace->id)->first();
        $credentialReady = AiProviderCredential::query()->where('workspace_id', $workspace->id)
            ->where('provider', 'openrouter')->where('status', 'healthy')
            ->whereNotNull('active_version_id')->exists();
        $models = config('ai.models', []);
        if ($task === null || ! is_array($models) || ! is_array($models[$task->model_id] ?? null)) {
            $this->block($blockers, 'message_model_unavailable', 'Configure an approved model for message drafting.');
        }
        if ($budget === null || (int) $budget->monthly_limit_micros <= 0) {
            $this->block($blockers, 'ai_budget_unavailable', 'Configure a positive workspace AI budget.');
        }
        if (! $credentialReady) {
            $this->block($blockers, 'openrouter_unavailable', 'Connect and test OpenRouter before starting the run.');
        }

        $armEstimates = [];
        $estimatedCost = 0;
        if ($task !== null) {
            foreach ($experiment->arms->sortBy('key.value') as $arm) {
                try {
                    $prompt = $this->prompts->handle($arm);
                    $estimate = $this->costs->openRouter(
                        (string) $task->model_id,
                        strlen($prompt['content']),
                        (int) config('ai.tasks.message_drafting.maximum_output_tokens', 2_000),
                    );
                    $armEstimates[] = [
                        'arm_id' => (string) $arm->id,
                        'input_hash' => $prompt['input_hash'],
                        'estimated_cost_micros' => $estimate->costMicros,
                    ];
                    $estimatedCost += $estimate->costMicros;
                    if ($estimate->costMicros > (int) $task->per_run_limit_micros) {
                        $this->block($blockers, 'arm_ai_budget_exceeded', 'An arm exceeds the configured message-drafting run limit.');
                    }
                } catch (Throwable) {
                    $this->block($blockers, 'draft_prompt_invalid', 'An arm cannot produce a bounded evidence-only drafting input.');
                }
            }
        }
        $policy = OutreachPolicy::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', OutreachPolicyStatus::Active->value)
            ->with('currentVersion')
            ->first();
        $objectiveMaximum = $version instanceof ObjectiveVersion
            ? (int) $version->maximum_ai_cost_micros
            : 0;
        $policyMaximum = $policy?->currentVersion === null
            ? 0
            : (int) $policy->currentVersion->maximum_ai_cost_micros;
        $effectiveMaximum = min($objectiveMaximum, $policyMaximum);
        if ($effectiveMaximum <= 0 || $estimatedCost > $effectiveMaximum) {
            $this->block($blockers, 'objective_ai_budget_exceeded', 'The bounded draft estimate exceeds the Objective or outreach-policy maximum.');
        }
        if ($armEstimates !== [] && ! $this->budgetGateway->mayAdmitBatch(
            $workspace,
            AiTask::MessageDrafting,
            array_map(static fn (array $arm): int => $arm['estimated_cost_micros'], $armEstimates),
            $effectiveMaximum,
        )) {
            $this->block($blockers, 'aggregate_ai_budget_unavailable', 'Current AI capacity cannot admit every arm together.');
        }

        $objectiveVersionId = $version instanceof ObjectiveVersion ? (string) $version->id : '';
        $taskPolicyVersion = $task instanceof AiTaskSetting ? (int) $task->policy_version : 0;
        $modelId = $task instanceof AiTaskSetting ? (string) $task->model_id : '';

        $inputs = [
            'version' => self::VERSION,
            'objective_id' => (string) $objective->id,
            'objective_version_id' => $objectiveVersionId,
            'objective_readiness_hash' => $objectiveReadiness->inputHash,
            'experiment_id' => (string) $experiment->id,
            'assignment_seed_hash' => (string) ($experiment->assignment_seed_hash ?? ''),
            'allocation_version' => (int) $experiment->allocation_version,
            'task_policy_version' => $taskPolicyVersion,
            'model_id' => $modelId,
            'arm_estimates' => $armEstimates,
            'maximum_cost_micros' => $effectiveMaximum,
        ];

        return new ObjectiveRunPreflightResult(
            $blockers,
            $warnings,
            $armEstimates,
            $estimatedCost,
            $effectiveMaximum,
            CanonicalObjectivePayload::hash($inputs),
        );
    }

    /** @param list<array{code:string,message:string}> $items */
    private function block(array &$items, string $code, string $message): void
    {
        if (! collect($items)->contains('code', $code)) {
            $items[] = compact('code', 'message');
        }
    }
}
