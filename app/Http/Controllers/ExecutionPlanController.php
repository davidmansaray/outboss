<?php

namespace App\Http\Controllers;

use App\Domain\Objectives\Services\ExecutionPlanApprovalView;
use App\Domain\Objectives\Services\ExecutionPlanExecutionView;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExecutionPlanController extends Controller
{
    public function show(
        Request $request,
        string $plan,
        ExecutionPlanApprovalView $approvalView,
        ExecutionPlanExecutionView $executionView,
    ): Response {
        $workspace = $request->user()->currentWorkspace();
        $model = ExecutionPlan::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($plan)
            ->with([
                'audienceSnapshot',
                'createdBy',
                'experiment',
                'objectiveRun.objectiveVersion',
                'actions' => fn ($query) => $query->orderBy('position')->orderBy('id'),
                'actions.audienceSnapshotMember',
            ])
            ->firstOrFail();

        $objectiveVersion = $model->objectiveRun->objectiveVersion;
        $inputManifest = $model->input_manifest;
        $omissions = [];
        $rawOmissions = $inputManifest['omitted'] ?? [];
        if (is_array($rawOmissions)) {
            foreach ($rawOmissions as $omission) {
                if (! is_array($omission)) {
                    continue;
                }
                $omissions[] = [
                    'member_id' => (string) ($omission['member_id'] ?? ''),
                    'reason' => (string) ($omission['reason'] ?? 'unspecified'),
                ];
            }
        }

        $approval = $approvalView->handle($model, $workspace->isOwner($request->user()));
        $execution = $executionView->handle(
            $model,
            $workspace->isOwner($request->user()),
            $approval,
        );

        return Inertia::render('execution-plans/Show', [
            'plan' => [
                'id' => (string) $model->id,
                'version' => (int) $model->version,
                'status' => $model->status->value,
                'created_at' => $model->created_at->toAtomString(),
                'compiled_at' => $model->compiled_at?->toAtomString(),
                'superseded_at' => $model->superseded_at?->toAtomString(),
                'created_by' => [
                    'id' => (int) $model->created_by_user_id,
                    'name' => (string) $model->createdBy->name,
                ],
                'objective' => [
                    'id' => (string) $model->objectiveRun->objective_id,
                    'run_id' => (string) $model->objective_run_id,
                    'version_id' => (string) $model->objective_version_id,
                    'name' => (string) $objectiveVersion->name,
                    'target_kind' => $objectiveVersion->target_kind->value,
                    'target_count' => $objectiveVersion->target_count !== null
                        ? (int) $objectiveVersion->target_count
                        : null,
                    'target_value' => $objectiveVersion->target_value !== null
                        ? (string) $objectiveVersion->target_value
                        : null,
                    'currency' => $objectiveVersion->currency,
                    'starts_at' => $objectiveVersion->starts_at->toAtomString(),
                    'ends_at' => $objectiveVersion->ends_at->toAtomString(),
                ],
                'lineage' => [
                    'audience_snapshot_id' => (string) $model->audience_snapshot_id,
                    'gtm_experiment_id' => $model->gtm_experiment_id,
                    'experiment_name' => $model->experiment?->name,
                    'conversion_definition_version_id' => (string) $model->conversion_definition_version_id,
                    'offer_version_id' => (string) $model->offer_version_id,
                    'outreach_policy_version_id' => (string) $model->outreach_policy_version_id,
                ],
                'model' => [
                    'id' => (string) $model->model_id,
                    'ai_task_version' => (string) $model->ai_task_version,
                    'prompt_version' => (string) $model->prompt_version,
                    'schema_version' => (string) $model->schema_version,
                    'validator_version' => (string) $model->validator_version,
                ],
                'counts' => [
                    'arms' => (int) $model->arm_count,
                    'accounts' => (int) $model->account_count,
                    'people' => (int) $model->people_count,
                    'actions' => (int) $model->action_count,
                    'omissions' => count($omissions),
                ],
                'audience_counts' => [
                    'included' => (int) $model->audienceSnapshot->included_people_count,
                    'excluded' => (int) $model->audienceSnapshot->excluded_people_count,
                    'uncertain' => (int) $model->audienceSnapshot->uncertain_people_count,
                    'suppressed' => (int) $model->audienceSnapshot->suppressed_people_count,
                ],
                'costs' => [
                    'maximum_micros' => (int) $model->maximum_cost_micros,
                    'estimated_micros' => (int) $model->estimated_cost_micros,
                    'actual_micros' => (int) $model->actual_cost_micros,
                ],
                'hashes' => [
                    'run_input' => (string) ($inputManifest['run_input_hash'] ?? ''),
                    'evidence_manifest' => (string) $model->evidence_manifest_hash,
                    'canonical_payload' => (string) $model->canonical_payload_hash,
                    'plan' => (string) $model->plan_hash,
                ],
                'omissions' => $omissions,
                'actions' => $model->actions->map(
                    fn (ExecutionPlanAction $action): array => $this->actionPayload($action),
                )->values()->all(),
            ],
            'approval' => $approval,
            'execution' => $execution,
            'safety' => [
                'creates_campaign' => false,
                'grants_sending_authority' => false,
                'compilation_sends_email' => false,
                'available_actions' => [
                    ...$approval['available_actions'],
                    ...$execution['available_actions'],
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function actionPayload(ExecutionPlanAction $action): array
    {
        return [
            'id' => (string) $action->id,
            'position' => (int) $action->position,
            'kind' => $action->action_kind->value,
            'status' => $action->state->value,
            'recipient' => [
                'address' => $action->destination_email_address,
                'person_id' => $action->person_id,
                'account_id' => $action->account_id,
                'audience_member_id' => (string) $action->audience_snapshot_member_id,
            ],
            'sender' => [
                'address' => (string) $action->sender_address_snapshot,
                'display_name' => $action->sender_display_name_snapshot,
                'reply_to' => $action->reply_to_snapshot,
                'mailbox_id' => (int) $action->mailbox_id,
                'provider_connection_id' => (int) $action->provider_connection_id,
                'provider_connection_version' => $action->provider_connection_version !== null
                    ? (int) $action->provider_connection_version
                    : null,
            ],
            'subject' => $action->subject,
            'body_text' => $action->body_text,
            'body_html' => $action->body_html,
            'schedule' => [
                'not_before' => $action->scheduled_not_before->toAtomString(),
                'not_after' => $action->scheduled_not_after->toAtomString(),
            ],
            'selected_claim_ids' => $action->selected_claim_ids,
            'selected_evidence_ids' => $action->selected_evidence_ids,
            'selected_claim_safety_hashes' => $action->selected_claim_safety_hashes,
            'evidence_review' => [
                'reason_to_contact_version_id' => $action->audienceSnapshotMember?->reason_to_contact_version_id,
                'fresh_until' => $action->audienceSnapshotMember?->evidence_fresh_until?->toAtomString(),
            ],
            'offer' => [
                'content_hash' => (string) $action->offer_content_hash,
                'contract_hash' => (string) $action->offer_contract_hash,
                'destination_url' => (string) $action->cta_destination_url,
                'response_route' => (string) $action->cta_response_route,
            ],
            'lineage' => [
                'experiment_arm_id' => (string) $action->gtm_experiment_arm_id,
                'experiment_assignment_id' => (string) $action->gtm_experiment_assignment_id,
                'objective_run_arm_draft_id' => (string) $action->objective_run_arm_draft_id,
                'strategy_version_id' => (string) $action->strategy_version_id,
                'offer_version_id' => (string) $action->offer_version_id,
                'conversion_definition_version_id' => (string) $action->conversion_definition_version_id,
            ],
            'hashes' => [
                'destination_email' => (string) $action->destination_email_hash,
                'template' => (string) $action->template_hash,
                'recipient_snapshot' => (string) $action->recipient_snapshot_hash,
                'content' => (string) $action->content_hash,
                'policy_input' => (string) $action->policy_input_hash,
                'action_fingerprint' => (string) $action->action_fingerprint,
            ],
        ];
    }
}
