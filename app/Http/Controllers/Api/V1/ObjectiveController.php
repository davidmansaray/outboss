<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Objectives\Services\CancelObjectiveRun;
use App\Domain\Objectives\Services\CreateGtmExperiment;
use App\Domain\Objectives\Services\CreateObjective;
use App\Domain\Objectives\Services\CreateStrategy;
use App\Domain\Objectives\Services\ExecutionPlanApprovalView;
use App\Domain\Objectives\Services\ExecutionPlanExecutionView;
use App\Domain\Objectives\Services\FreezeExperimentAssignments;
use App\Domain\Objectives\Services\ObjectiveReadiness;
use App\Domain\Objectives\Services\ObjectiveRunPreflight;
use App\Domain\Objectives\Services\StartObjectiveRun;
use App\Domain\Objectives\Services\SupersedeObjectiveRun;
use App\Domain\Objectives\Services\TransitionObjective;
use App\Domain\Objectives\Services\VersionObjective;
use App\Domain\Objectives\Services\VersionStrategy;
use App\Http\Controllers\Controller;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\GtmExperiment;
use App\Models\GtmExperimentAssignment;
use App\Models\Objective;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use App\Models\ObjectiveRunPhase;
use App\Models\ObjectiveStateEvent;
use App\Models\ObjectiveVersion;
use App\Models\Strategy;
use App\Models\StrategyVersion;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ObjectiveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $objectives = Objective::query()->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->with('currentVersion')->latest()->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return response()->json([
            'data' => collect($objectives->items())->map(fn (Objective $objective): array => $this->objectivePayload($objective))->all(),
            'meta' => [
                'current_page' => $objectives->currentPage(),
                'last_page' => $objectives->lastPage(),
                'per_page' => $objectives->perPage(),
                'total' => $objectives->total(),
            ],
        ]);
    }

    public function show(Request $request, string $objective): JsonResponse
    {
        $objectiveModel = $this->objective($request, $objective)->load(['currentVersion', 'stateEvents']);

        return response()->json(['data' => [
            ...$this->objectivePayload($objectiveModel),
            'timeline' => $objectiveModel->stateEvents->map(fn (ObjectiveStateEvent $event): array => [
                'id' => $event->id,
                'objective_version_id' => $event->objective_version_id,
                'from_state' => $event->from_state?->value,
                'to_state' => $event->to_state->value,
                'reason_code' => $event->reason_code,
                'context' => $event->context,
                'created_at' => $event->created_at->toAtomString(),
            ])->all(),
        ]]);
    }

    public function store(Request $request, CreateObjective $create): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request, $workspace);

        return response()->json(['data' => $this->objectivePayload($create->handle($workspace, $request->user()))], 201);
    }

    public function version(Request $request, string $objective, VersionObjective $version): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request, $workspace);
        $data = $request->validate($this->objectiveRules());
        $created = $version->handle(
            $this->objective($request, $objective),
            $request->user(),
            $data,
            (int) $data['expected_lock_version'],
        );

        return response()->json(['data' => $this->objectiveVersionPayload($created)], 201);
    }

    public function readiness(Request $request, string $objective, ObjectiveReadiness $readiness): JsonResponse
    {
        $result = $readiness->handle($this->objective($request, $objective));

        return response()->json(['data' => [
            'ready' => $result->ready(),
            'blockers' => $result->blockers,
            'warnings' => $result->warnings,
            'input_hash' => $result->inputHash,
        ]]);
    }

    public function transition(Request $request, string $objective, TransitionObjective $transition): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request, $workspace);
        $data = $request->validate([
            'status' => ['required', 'string', 'in:ready,active,blocked,paused,completed,expired,cancelled'],
            'expected_lock_version' => ['required', 'integer', 'min:1'],
            'reason_code' => ['nullable', 'string', 'max:80'],
        ]);
        $updated = $transition->handle(
            $this->objective($request, $objective),
            $request->user(),
            $data['status'],
            (int) $data['expected_lock_version'],
            $data['reason_code'] ?? null,
        );

        return response()->json(['data' => $this->objectivePayload($updated)]);
    }

    public function storeStrategy(Request $request, CreateStrategy $create): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request, $workspace);
        $strategy = $create->handle($workspace, $request->user(), $request->validate($this->strategyRules()));

        return response()->json(['data' => $this->strategyPayload($strategy)], 201);
    }

    public function showStrategy(Request $request, string $strategy): JsonResponse
    {
        return response()->json(['data' => $this->strategyPayload(
            $this->strategy($request, $strategy)->load('currentVersion'),
        )]);
    }

    public function versionStrategy(Request $request, string $strategy, VersionStrategy $version): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request, $workspace);
        $data = $request->validate([
            ...$this->strategyRules(),
            'expected_lock_version' => ['required', 'integer', 'min:1'],
        ]);
        $created = $version->handle(
            $this->strategy($request, $strategy),
            $request->user(),
            $data,
            (int) $data['expected_lock_version'],
        );

        return response()->json(['data' => $this->strategyVersionPayload($created)], 201);
    }

    public function storeExperiment(Request $request, string $objective, CreateGtmExperiment $create): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request, $workspace);
        $objectiveModel = $this->objective($request, $objective);
        $data = $request->validate([
            'objective_version_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:160'],
            'hypothesis' => ['nullable', 'string', 'max:4000'],
            'guardrails' => ['sometimes', 'array'],
            'arms' => ['required', 'array', 'min:1', 'max:2'],
            'arms.*.strategy_version_id' => ['required', 'uuid'],
            'arms.*.key' => ['required', 'string', 'in:control,treatment'],
            'arms.*.label' => ['sometimes', 'string', 'max:100'],
            'arms.*.allocation_basis_points' => ['required', 'integer', 'min:1', 'max:10000'],
            'arms.*.declared_differences' => ['sometimes', 'array'],
        ]);
        $version = ObjectiveVersion::query()->where('workspace_id', $workspace->id)
            ->where('objective_id', $objectiveModel->id)->whereKey($data['objective_version_id'])->firstOrFail();
        $data['objective_version_id'] = $version->id;
        $experiment = $create->handle($workspace, $request->user(), $data);

        return response()->json(['data' => $this->experimentPayload($experiment)], 201);
    }

    public function showExperiment(Request $request, string $experiment): JsonResponse
    {
        return response()->json(['data' => $this->experimentPayload($this->experiment($request, $experiment)->load(['arms', 'assignments.members']))]);
    }

    public function freezeAssignments(Request $request, string $experiment, FreezeExperimentAssignments $freeze): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request, $workspace);
        $data = $request->validate(['expected_lock_version' => ['required', 'integer', 'min:1']]);
        $updated = $freeze->handle($this->experiment($request, $experiment), $request->user(), (int) $data['expected_lock_version']);

        return response()->json(['data' => $this->experimentPayload($updated)]);
    }

    public function preflightRun(Request $request, string $objective, ObjectiveRunPreflight $preflight): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request, $workspace);
        $data = $request->validate(['gtm_experiment_id' => ['required', 'uuid']]);
        $result = $preflight->handle(
            $this->objective($request, $objective),
            $this->experiment($request, (string) $data['gtm_experiment_id']),
        );

        return response()->json(['data' => [
            'ready' => $result->ready(),
            'blockers' => $result->blockers,
            'warnings' => $result->warnings,
            'arm_estimates' => $result->armEstimates,
            'estimated_cost_micros' => $result->estimatedCostMicros,
            'maximum_cost_micros' => $result->effectiveMaximumCostMicros,
            'input_hash' => $result->inputHash,
        ]]);
    }

    public function startRun(Request $request, string $objective, StartObjectiveRun $start): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request, $workspace);
        $data = $request->validate(['gtm_experiment_id' => ['required', 'uuid']]);
        $key = trim((string) $request->header('Idempotency-Key', ''));
        $run = $start->handle(
            $this->objective($request, $objective),
            $this->experiment($request, (string) $data['gtm_experiment_id']),
            $request->user(),
            $key,
        );

        return response()->json(['data' => $this->runPayload($run)], 202);
    }

    public function showRun(Request $request, string $run): JsonResponse
    {
        $model = $this->run($request, $run)->load(['phases', 'armDrafts', 'executionPlans']);

        return response()->json(['data' => $this->runPayload($model)]);
    }

    public function cancelRun(Request $request, string $run, CancelObjectiveRun $cancel): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request, $workspace);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->runPayload(
            $cancel->handle($this->run($request, $run), $request->user(), (string) $data['reason']),
        )]);
    }

    public function supersedeRun(Request $request, string $run, SupersedeObjectiveRun $supersede): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request, $workspace);
        $data = $request->validate([
            'gtm_experiment_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $successor = $supersede->handle(
            $this->run($request, $run),
            $this->experiment($request, (string) $data['gtm_experiment_id']),
            $request->user(),
            trim((string) $request->header('Idempotency-Key', '')),
            (string) $data['reason'],
        );

        return response()->json(['data' => $this->runPayload($successor)], 202);
    }

    public function showPlan(
        Request $request,
        string $plan,
        ExecutionPlanApprovalView $approvalView,
        ExecutionPlanExecutionView $executionView,
    ): JsonResponse {
        $model = ExecutionPlan::query()->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey($plan)->with('actions')->firstOrFail();
        $owner = $request->user()->currentWorkspace()->isOwner($request->user());
        $approval = $approvalView->handle($model, $owner);

        return response()->json(['data' => [
            ...$this->planPayload($model, true),
            'approval' => $approval,
            'execution' => $executionView->handle($model, $owner, $approval),
        ]]);
    }

    private function objective(Request $request, string $id): Objective
    {
        return Objective::query()->where('workspace_id', $request->user()->currentWorkspace()->id)->whereKey($id)->firstOrFail();
    }

    private function strategy(Request $request, string $id): Strategy
    {
        return Strategy::query()->where('workspace_id', $request->user()->currentWorkspace()->id)->whereKey($id)->firstOrFail();
    }

    private function experiment(Request $request, string $id): GtmExperiment
    {
        return GtmExperiment::query()->where('workspace_id', $request->user()->currentWorkspace()->id)->whereKey($id)->firstOrFail();
    }

    private function run(Request $request, string $id): ObjectiveRun
    {
        return ObjectiveRun::query()->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey($id)->firstOrFail();
    }

    private function assertOwner(Request $request, Workspace $workspace): void
    {
        abort_unless($workspace->isOwner($request->user()), 403, 'Only the workspace owner can change governed Objective inputs.');
    }

    /** @return array<string, mixed> */
    private function objectiveRules(): array
    {
        return [
            'expected_lock_version' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'conversion_definition_id' => ['required', 'uuid'],
            'conversion_definition_version_id' => ['required', 'uuid'],
            'segment_version_id' => ['required', 'uuid'],
            'audience_snapshot_id' => ['required', 'uuid'],
            'offer_id' => ['required', 'uuid'],
            'offer_version_id' => ['required', 'uuid'],
            'target_kind' => ['required', 'string', 'in:count,value'],
            'target_count' => ['nullable', 'integer', 'min:1'],
            'target_value' => ['nullable', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'attribution_window_days' => ['required', 'integer', 'min:1'],
            'maximum_people' => ['required', 'integer', 'min:1'],
            'maximum_accounts' => ['required', 'integer', 'min:1'],
            'maximum_messages' => ['required', 'integer', 'min:1'],
            'maximum_ai_cost_micros' => ['required', 'integer', 'min:1'],
            'required_qualification_outcomes' => ['required', 'array', 'min:1', 'max:10'],
            'required_qualification_outcomes.*' => ['string'],
            'minimum_qualification_confidence' => ['required', 'numeric', 'between:0,1'],
            'maximum_evidence_age_days' => ['required', 'integer', 'min:1', 'max:365'],
            'exclusions' => ['sometimes', 'array'],
            'authority_envelope' => ['required', 'array'],
            'forecast_assumptions' => ['sometimes', 'array'],
        ];
    }

    /** @return array<string, mixed> */
    private function strategyRules(): array
    {
        return [
            'objective_version_id' => ['required', 'uuid'],
            'audience_snapshot_id' => ['required', 'uuid'],
            'offer_version_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:160'],
            'positioning' => ['required', 'string', 'max:4000'],
            'problem_frame' => ['required', 'string', 'max:4000'],
            'proof_summary' => ['required', 'string', 'max:4000'],
            'cta_kind' => ['required', 'string', 'max:48'],
            'cta_text' => ['required', 'string', 'max:500'],
            'proof_claim_ids' => ['required', 'array', 'min:1', 'max:50'],
            'proof_claim_ids.*' => ['uuid'],
            'personalisation_contract' => ['required', 'array'],
            'message_generation_contract' => ['required', 'array'],
            'stop_conditions' => ['required', 'array'],
        ];
    }

    /** @return array<string, mixed> */
    private function objectivePayload(Objective $objective): array
    {
        return [
            'id' => $objective->id,
            'status' => $objective->status->value,
            'status_reason_code' => $objective->status_reason_code,
            'lock_version' => $objective->lock_version,
            'current_version' => $objective->currentVersion === null ? null : $this->objectiveVersionPayload($objective->currentVersion),
            'created_at' => $objective->created_at?->toAtomString(),
            'updated_at' => $objective->updated_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function objectiveVersionPayload(ObjectiveVersion $version): array
    {
        return collect($version->toArray())->only([
            'id', 'version', 'name', 'description', 'conversion_definition_id', 'conversion_definition_version_id',
            'segment_version_id', 'audience_snapshot_id', 'offer_id', 'offer_version_id', 'target_kind',
            'target_count', 'target_value', 'currency', 'starts_at', 'ends_at', 'attribution_window_days',
            'maximum_people', 'maximum_accounts', 'maximum_messages', 'maximum_ai_cost_micros',
            'required_qualification_outcomes', 'minimum_qualification_confidence', 'maximum_evidence_age_days',
            'exclusions', 'authority_envelope', 'forecast_assumptions', 'forecast_projection', 'schema_version',
            'content_hash', 'created_at',
        ])->map(fn (mixed $value): mixed => $value instanceof \BackedEnum ? $value->value : $value)->all();
    }

    /** @return array<string, mixed> */
    private function strategyPayload(Strategy $strategy): array
    {
        return [
            'id' => $strategy->id,
            'status' => $strategy->status->value,
            'lock_version' => $strategy->lock_version,
            'current_version' => $strategy->currentVersion === null ? null : $this->strategyVersionPayload($strategy->currentVersion),
        ];
    }

    /** @return array<string, mixed> */
    private function strategyVersionPayload(StrategyVersion $version): array
    {
        return collect($version->toArray())->except(['workspace_id', 'created_by_user_id'])->all();
    }

    /** @return array<string, mixed> */
    private function experimentPayload(GtmExperiment $experiment): array
    {
        $experiment->loadMissing(['arms', 'assignments.members']);

        return [
            'id' => $experiment->id,
            'objective_id' => $experiment->objective_id,
            'objective_version_id' => $experiment->objective_version_id,
            'audience_snapshot_id' => $experiment->audience_snapshot_id,
            'name' => $experiment->name,
            'hypothesis' => $experiment->hypothesis,
            'status' => $experiment->status->value,
            'assignment_unit' => $experiment->assignment_unit,
            'assignment_seed_hash' => $experiment->assignment_seed_hash,
            'assignment_algorithm_version' => $experiment->assignment_algorithm_version,
            'allocation_version' => $experiment->allocation_version,
            'guardrails' => $experiment->guardrails,
            'starts_at' => $experiment->starts_at->toAtomString(),
            'ends_at' => $experiment->ends_at->toAtomString(),
            'lock_version' => $experiment->lock_version,
            'arms' => $experiment->arms->map(fn ($arm): array => [
                'id' => $arm->id,
                'strategy_version_id' => $arm->strategy_version_id,
                'offer_version_id' => $arm->offer_version_id,
                'key' => $arm->key->value,
                'label' => $arm->label,
                'allocation_basis_points' => $arm->allocation_basis_points,
                'declared_differences' => $arm->declared_differences,
            ])->all(),
            'assignments' => $experiment->assignments->map(fn (GtmExperimentAssignment $assignment): array => [
                'id' => $assignment->id,
                'arm_id' => $assignment->gtm_experiment_arm_id,
                'account_id' => $assignment->account_id,
                'assignment_unit_key_hash' => $assignment->assignment_unit_key_hash,
                'bucket' => $assignment->bucket,
                'eligibility_snapshot_hash' => $assignment->eligibility_snapshot_hash,
                'member_count' => $assignment->members->count(),
                'assigned_at' => $assignment->assigned_at->toAtomString(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function runPayload(ObjectiveRun $run): array
    {
        $run->loadMissing(['phases', 'armDrafts', 'executionPlans']);

        return [
            'id' => $run->id,
            'objective_id' => $run->objective_id,
            'objective_version_id' => $run->objective_version_id,
            'audience_snapshot_id' => $run->audience_snapshot_id,
            'gtm_experiment_id' => $run->gtm_experiment_id,
            'run_number' => $run->run_number,
            'state' => $run->state->value,
            'current_phase' => $run->current_phase->value,
            'input_hash' => $run->input_hash,
            'schema_version' => $run->schema_version,
            'costs' => [
                'maximum_micros' => $run->maximum_cost_micros,
                'reserved_micros' => $run->reserved_cost_micros,
                'actual_micros' => $run->actual_cost_micros,
            ],
            'safe_error_code' => $run->safe_error_code,
            'safe_error_detail' => $run->safe_error_detail,
            'phases' => $run->phases->sortBy(['attempt_number'])->map(fn (ObjectiveRunPhase $phase): array => [
                'id' => $phase->id,
                'phase' => $phase->phase->value,
                'attempt_number' => $phase->attempt_number,
                'state' => $phase->state->value,
                'input_hash' => $phase->input_hash,
                'output_hash' => $phase->output_hash,
                'safe_error_code' => $phase->safe_error_code,
                'queued_at' => $phase->queued_at?->toAtomString(),
                'started_at' => $phase->started_at?->toAtomString(),
                'finished_at' => $phase->finished_at?->toAtomString(),
            ])->values()->all(),
            'arm_drafts' => $run->armDrafts->map(fn (ObjectiveRunArmDraft $draft): array => [
                'id' => $draft->id,
                'arm_id' => $draft->gtm_experiment_arm_id,
                'state' => $draft->state->value,
                'model_id' => $draft->model_id,
                'prompt_version' => $draft->prompt_version,
                'schema_version' => $draft->schema_version,
                'validator_version' => $draft->validator_version,
                'input_hash' => $draft->input_hash,
                'output_hash' => $draft->output_hash,
                'template_hash' => $draft->template_hash,
                'safe_error_code' => $draft->safe_error_code,
            ])->all(),
            'plans' => $run->executionPlans->map(fn (ExecutionPlan $plan): array => $this->planPayload($plan, false))->all(),
            'started_at' => $run->started_at?->toAtomString(),
            'finished_at' => $run->finished_at?->toAtomString(),
            'created_at' => $run->created_at?->toAtomString(),
            'updated_at' => $run->updated_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function planPayload(ExecutionPlan $plan, bool $includeExactActions): array
    {
        $plan->loadMissing('actions.audienceSnapshotMember');

        return [
            'id' => $plan->id,
            'objective_run_id' => $plan->objective_run_id,
            'version' => $plan->version,
            'status' => $plan->status->value,
            'objective_version_id' => $plan->objective_version_id,
            'audience_snapshot_id' => $plan->audience_snapshot_id,
            'gtm_experiment_id' => $plan->gtm_experiment_id,
            'conversion_definition_version_id' => $plan->conversion_definition_version_id,
            'offer_version_id' => $plan->offer_version_id,
            'outreach_policy_version_id' => $plan->outreach_policy_version_id,
            'model_id' => $plan->model_id,
            'prompt_version' => $plan->prompt_version,
            'schema_version' => $plan->schema_version,
            'validator_version' => $plan->validator_version,
            'counts' => [
                'arms' => $plan->arm_count,
                'accounts' => $plan->account_count,
                'people' => $plan->people_count,
                'actions' => $plan->action_count,
            ],
            'costs' => [
                'maximum_micros' => $plan->maximum_cost_micros,
                'estimated_micros' => $plan->estimated_cost_micros,
                'actual_micros' => $plan->actual_cost_micros,
            ],
            'input_manifest' => $plan->input_manifest,
            'evidence_manifest_hash' => $plan->evidence_manifest_hash,
            'canonical_payload_hash' => $plan->canonical_payload_hash,
            'plan_hash' => $plan->plan_hash,
            'compiled_at' => $plan->compiled_at?->toAtomString(),
            'actions' => $plan->actions->map(fn (ExecutionPlanAction $action): array => [
                'id' => $action->id,
                'position' => $action->position,
                'arm_id' => $action->gtm_experiment_arm_id,
                'assignment_id' => $action->gtm_experiment_assignment_id,
                'objective_run_arm_draft_id' => $action->objective_run_arm_draft_id,
                'strategy_version_id' => $action->strategy_version_id,
                'audience_snapshot_member_id' => $action->audience_snapshot_member_id,
                'person_id' => $action->person_id,
                'account_id' => $action->account_id,
                'offer_version_id' => $action->offer_version_id,
                'conversion_definition_version_id' => $action->conversion_definition_version_id,
                'action_kind' => $action->action_kind->value,
                'state' => $action->state->value,
                'destination_email_hash' => $action->destination_email_hash,
                'destination_email_address' => $includeExactActions ? $action->destination_email_address : null,
                'subject' => $includeExactActions ? $action->subject : null,
                'body_text' => $includeExactActions ? $action->body_text : null,
                'body_html' => $includeExactActions ? $action->body_html : null,
                'selected_claim_ids' => $action->selected_claim_ids,
                'selected_evidence_ids' => $action->selected_evidence_ids,
                'selected_claim_safety_hashes' => $action->selected_claim_safety_hashes,
                'reason_to_contact_version_id' => $action->audienceSnapshotMember?->reason_to_contact_version_id,
                'evidence_fresh_until' => $action->audienceSnapshotMember?->evidence_fresh_until?->toAtomString(),
                'offer_content_hash' => $action->offer_content_hash,
                'offer_contract_hash' => $action->offer_contract_hash,
                'cta_destination_url' => $includeExactActions ? $action->cta_destination_url : null,
                'cta_response_route' => $action->cta_response_route,
                'mailbox_id' => $action->mailbox_id,
                'provider_connection_id' => $action->provider_connection_id,
                'provider_connection_version' => $action->provider_connection_version,
                'sender_address' => $includeExactActions ? $action->sender_address_snapshot : null,
                'sender_display_name' => $includeExactActions ? $action->sender_display_name_snapshot : null,
                'reply_to' => $includeExactActions ? $action->reply_to_snapshot : null,
                'scheduled_not_before' => $action->scheduled_not_before->toAtomString(),
                'scheduled_not_after' => $action->scheduled_not_after->toAtomString(),
                'template_hash' => $action->template_hash,
                'recipient_snapshot_hash' => $action->recipient_snapshot_hash,
                'content_hash' => $action->content_hash,
                'policy_input_hash' => $action->policy_input_hash,
                'action_fingerprint' => $action->action_fingerprint,
            ])->all(),
            'created_at' => $plan->created_at->toAtomString(),
        ];
    }
}
