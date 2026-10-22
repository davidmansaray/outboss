<?php

namespace App\Http\Controllers;

use App\Domain\Conversions\Services\InitializeMeetingMeasurement;
use App\Domain\Objectives\Enums\GtmExperimentStatus;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Domain\Objectives\Enums\ObjectiveStatus;
use App\Domain\Objectives\Services\ImportLegacyExecutionAuthority;
use App\Domain\Objectives\Services\ObjectiveReadiness;
use App\Domain\Objectives\Services\SaveObjectiveGoal;
use App\Models\Campaign;
use App\Models\ConversionDefinition;
use App\Models\ExecutionAuthorityLegacyMapping;
use App\Models\ExecutionPlan;
use App\Models\GtmExperiment;
use App\Models\Objective;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Primary workspace entry point for existing governed Objectives and plans. */
final class ObjectiveController extends Controller
{
    public function create(Request $request): Response
    {
        abort_unless($request->user()->currentWorkspace()->isOwner($request->user()), 403);

        return Inertia::render('objectives/Create');
    }

    public function store(Request $request, SaveObjectiveGoal $save): RedirectResponse
    {
        $objective = $save->handle($request->user()->currentWorkspace(), $request->user(), $request->all());

        return redirect()->route('objectives.show', $objective);
    }

    public function show(Request $request, Objective $objective): Response
    {
        abort_unless((int) $objective->workspace_id === (int) $request->user()->currentWorkspace()->id, 404);
        $goal = $objective->currentGoalVersion;
        abort_if($goal === null, 404);

        return Inertia::render('objectives/Show', [
            'return_campaign' => $this->returnCampaign($request)?->only(['id', 'name']),
            'authority' => $goal->execution_authority,
            'can_import_authority' => $request->user()->currentWorkspace()->isOwner($request->user()),
            'legacy_scope_id' => ExecutionAuthorityLegacyMapping::query()->where('workspace_id', $objective->workspace_id)
                ->where('objective_id', $objective->id)->value('execution_authority_scope_id'),
            'definition_version_id' => $goal->conversion_definition_version_id,
            'definitions' => ConversionDefinition::query()->where('workspace_id', $objective->workspace_id)
                ->where('event_key', $goal->measurement)->where('status', 'active')->with('currentVersion')->get()
                ->map(fn ($definition) => ['id' => $definition->current_version_id, 'name' => $definition->currentVersion?->display_name])->values(),
            'objective' => [
                'id' => $objective->id,
                'lock_version' => $objective->lock_version,
                'name' => $goal->name,
                'description' => $goal->description,
                'measurement' => $goal->measurement,
                'target_count' => $goal->target_count,
                'deadline' => $goal->deadline?->format('Y-m-d'),
                'version' => $goal->version,
            ],
            'can_edit' => $request->user()->currentWorkspace()->isOwner($request->user())
                && $objective->status === ObjectiveStatus::Draft && $objective->current_version_id === null
                && ! $objective->runs()->where('origin', '!=', 'human')->exists(),
        ]);
    }

    public function update(Request $request, Objective $objective, SaveObjectiveGoal $save): RedirectResponse
    {
        $this->returnCampaign($request);
        $save->handle($request->user()->currentWorkspace(), $request->user(), $request->all(), $objective);

        return $this->returnToObjective($request, $objective);
    }

    public function importAuthority(Request $request, Objective $objective, ImportLegacyExecutionAuthority $import): RedirectResponse
    {
        abort_unless((int) $objective->workspace_id === (int) $request->user()->currentWorkspace()->id, 404);
        $this->returnCampaign($request);
        $import->handle($objective, $request->user());

        return $this->returnToObjective($request, $objective);
    }

    public function measurement(Request $request, Objective $objective, InitializeMeetingMeasurement $initialize): RedirectResponse
    {
        abort_unless((int) $objective->workspace_id === (int) $request->user()->currentWorkspace()->id, 404);
        abort_unless($objective->currentGoalVersion !== null, 404);
        $this->returnCampaign($request);
        $initialize->handle($request->user()->currentWorkspace(), $request->user());

        return $this->returnToObjective($request, $objective);
    }

    private function returnCampaign(Request $request): ?Campaign
    {
        if (! $request->has('from_campaign')) {
            return null;
        }
        $data = $request->validate(['from_campaign' => ['required', 'integer', 'min:1']]);
        $campaign = Campaign::query()->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey($data['from_campaign'])->firstOrFail();
        $this->authorize('view', $campaign);

        return $campaign;
    }

    private function returnToObjective(Request $request, Objective $objective): RedirectResponse
    {
        return redirect()->route('objectives.show', [
            'objective' => $objective, 'from_campaign' => $request->input('from_campaign'),
        ]);
    }

    public function index(Request $request, ObjectiveReadiness $readiness): Response
    {
        $workspace = $request->user()->currentWorkspace();
        /** @var Collection<int, Objective> $objectives */
        $objectives = Objective::query()
            ->where('workspace_id', $workspace->id)
            ->with(['currentVersion', 'currentPlan', 'currentGoalVersion'])
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        $objectiveIds = $objectives->pluck('id')->map(strval(...))->all();
        $experiments = $objectiveIds === []
            ? collect()
            : GtmExperiment::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('objective_id', $objectiveIds)
                ->withCount(['arms', 'assignments'])
                ->latest('created_at')
                ->get()
                ->groupBy('objective_id');
        $runs = $objectiveIds === []
            ? collect()
            : ObjectiveRun::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('objective_id', $objectiveIds)
                ->with('executionPlans')
                ->orderByDesc('run_number')
                ->get()
                ->groupBy('objective_id');
        $owner = $workspace->isOwner($request->user());

        return Inertia::render('objectives/Index', [
            'is_owner' => $owner,
            'objectives' => $objectives->map(function (Objective $objective) use ($experiments, $runs, $readiness, $owner): array {
                $version = $objective->currentVersion;
                $goal = $objective->currentGoalVersion;
                $experiment = $this->currentExperiment(
                    $version,
                    $experiments->get((string) $objective->id, collect()),
                );
                $latestRun = $runs->get((string) $objective->id, collect())->first();
                $plan = $objective->currentPlan;
                if (! $plan instanceof ExecutionPlan && $latestRun instanceof ObjectiveRun) {
                    $plan = $latestRun->executionPlans->sortByDesc('version')->first();
                }
                $assessment = $this->readiness($objective, $readiness);
                $startBlockers = $this->startBlockers($objective, $experiment, $latestRun, $assessment['blockers']);
                $attentionBlockers = $this->attentionBlockers($latestRun, $assessment['blockers'], $startBlockers);
                $executionUnderway = $this->executionUnderway($latestRun) && $attentionBlockers === [];

                return [
                    'id' => (string) $objective->id,
                    'status' => $objective->status->value,
                    'name' => $goal->name ?? ($version instanceof ObjectiveVersion ? (string) $version->name : 'Unconfigured Objective'),
                    'href' => $goal ? route('objectives.show', $objective, false) : null,
                    'description' => $version instanceof ObjectiveVersion ? $version->description : null,
                    'target' => $version instanceof ObjectiveVersion ? [
                        'kind' => $version->target_kind->value,
                        'count' => $version->target_count,
                        'value' => $version->target_value,
                        'currency' => $version->currency,
                    ] : ($goal && $goal->target_count !== null ? ['kind' => 'count', 'count' => $goal->target_count, 'value' => null, 'currency' => null] : null),
                    'horizon' => $version instanceof ObjectiveVersion ? [
                        'starts_at' => $version->starts_at->toAtomString(),
                        'ends_at' => $version->ends_at->toAtomString(),
                    ] : null,
                    'readiness' => [
                        'ready' => $assessment['blockers'] === [],
                        'blockers' => $assessment['blockers'],
                        'warnings' => $assessment['warnings'],
                    ],
                    'experiment' => $experiment instanceof GtmExperiment ? [
                        'id' => (string) $experiment->id,
                        'name' => (string) $experiment->name,
                        'status' => $experiment->status->value,
                        'arms' => (int) $experiment->getAttribute('arms_count'),
                        'assignments' => (int) $experiment->getAttribute('assignments_count'),
                    ] : null,
                    'latest_run' => $latestRun instanceof ObjectiveRun ? [
                        'id' => (string) $latestRun->id,
                        'number' => (int) $latestRun->run_number,
                        'state' => $latestRun->state->value,
                        'phase' => $latestRun->current_phase->value,
                        'updated_at' => $latestRun->updated_at?->toAtomString(),
                    ] : null,
                    'plan' => $plan instanceof ExecutionPlan ? [
                        'id' => (string) $plan->id,
                        'version' => (int) $plan->version,
                        'status' => $plan->status->value,
                        'actions' => (int) $plan->action_count,
                        'href' => route('execution-plans.show', $plan, false),
                    ] : null,
                    'start_blockers' => $startBlockers,
                    'attention_blockers' => $attentionBlockers,
                    'execution_underway' => $executionUnderway,
                    'can_start_run' => $owner && $startBlockers === [],
                    'updated_at' => $objective->updated_at?->toAtomString(),
                ];
            })->values()->all(),
            'focus' => $request->string('focus')->toString(),
        ]);
    }

    /**
     * @param  Collection<int, GtmExperiment>  $experiments
     */
    private function currentExperiment(?ObjectiveVersion $version, Collection $experiments): ?GtmExperiment
    {
        if (! $version instanceof ObjectiveVersion) {
            return null;
        }

        $current = $experiments
            ->filter(fn (GtmExperiment $experiment): bool => hash_equals(
                (string) $experiment->objective_version_id,
                (string) $version->id,
            ));

        return $current
            ->filter(fn (GtmExperiment $experiment): bool => $experiment->status === GtmExperimentStatus::Assigned)
            ->sortByDesc('created_at')
            ->first()
            ?? $current->sortByDesc('created_at')->first();
    }

    /** @return array{blockers:list<array{code:string,message:string}>,warnings:list<array{code:string,message:string}>} */
    private function readiness(Objective $objective, ObjectiveReadiness $readiness): array
    {
        try {
            $assessment = $readiness->handle($objective);

            return ['blockers' => $assessment->blockers, 'warnings' => $assessment->warnings];
        } catch (Throwable) {
            return [
                'blockers' => [[
                    'code' => 'objective_readiness_unavailable',
                    'message' => 'Current Objective readiness could not be verified safely.',
                ]],
                'warnings' => [],
            ];
        }
    }

    /**
     * @param  list<array{code:string,message:string}>  $readinessBlockers
     * @return list<array{code:string,message:string}>
     */
    private function startBlockers(
        Objective $objective,
        ?GtmExperiment $experiment,
        ?ObjectiveRun $latestRun,
        array $readinessBlockers,
    ): array {
        $blockers = $readinessBlockers;
        if ($objective->status !== ObjectiveStatus::Ready) {
            $blockers[] = [
                'code' => 'objective_not_ready',
                'message' => 'Move this Objective to ready before generating a plan.',
            ];
        }
        if (! $experiment instanceof GtmExperiment) {
            $blockers[] = [
                'code' => 'experiment_missing',
                'message' => 'Create and freeze an experiment for this exact Objective Version.',
            ];
        } elseif ($experiment->status !== GtmExperimentStatus::Assigned) {
            $blockers[] = [
                'code' => 'experiment_not_assigned',
                'message' => 'Freeze the current experiment assignments before generating a plan.',
            ];
        }
        if ($latestRun instanceof ObjectiveRun && in_array($latestRun->state, [
            ObjectiveRunState::Planned,
            ObjectiveRunState::Running,
            ObjectiveRunState::Waiting,
            ObjectiveRunState::NeedsReview,
            ObjectiveRunState::Paused,
        ], true)) {
            $blockers[] = [
                'code' => 'run_already_active',
                'message' => 'This Objective already has a nonterminal run.',
            ];
        }

        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker['code']] = $blocker;
        }

        return array_values($unique);
    }

    /**
     * @param  list<array{code:string,message:string}>  $readinessBlockers
     * @param  list<array{code:string,message:string}>  $startBlockers
     * @return list<array{code:string,message:string}>
     */
    private function attentionBlockers(?ObjectiveRun $latestRun, array $readinessBlockers, array $startBlockers): array
    {
        if (! $this->activeRun($latestRun)) {
            return $startBlockers;
        }

        if ($latestRun?->state === ObjectiveRunState::NeedsReview) {
            $readinessBlockers[] = [
                'code' => 'run_needs_review',
                'message' => 'Review the generated plan before execution can continue.',
            ];
        } elseif ($latestRun?->state === ObjectiveRunState::Paused) {
            $readinessBlockers[] = [
                'code' => 'run_paused',
                'message' => 'This Objective run is paused and needs an owner decision.',
            ];
        }

        return $readinessBlockers;
    }

    private function executionUnderway(?ObjectiveRun $latestRun): bool
    {
        return $latestRun instanceof ObjectiveRun && in_array($latestRun->state, [
            ObjectiveRunState::Planned,
            ObjectiveRunState::Running,
            ObjectiveRunState::Waiting,
        ], true);
    }

    private function activeRun(?ObjectiveRun $latestRun): bool
    {
        return $latestRun instanceof ObjectiveRun && in_array($latestRun->state, [
            ObjectiveRunState::Planned,
            ObjectiveRunState::Running,
            ObjectiveRunState::Waiting,
            ObjectiveRunState::NeedsReview,
            ObjectiveRunState::Paused,
        ], true);
    }
}
