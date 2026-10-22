<?php

namespace App\Domain\Objectives\Support;

use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Services\BuildHumanExecutionPlan;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\ExecutionAuthorityScope;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use Illuminate\Database\Eloquent\Collection;

/** The exact bytes and lineage an owner must see and echo before deciding. */
final class ApprovalManifest
{
    public const VERSION = 'execution_plan_approval_display_v1';

    public const HUMAN_VERSION = 'human_plan_approval_display_v3';

    /**
     * @param  Collection<int, ExecutionPlanAction>|null  $actions
     * @return array<string,mixed>
     */
    public function build(ExecutionPlan $plan, ?Collection $actions = null): array
    {
        $actions ??= $plan->actions()->orderBy('id')->get();
        $actionPayloads = $actions->map(function (ExecutionPlanAction $action): array {
            $payload = [
                'id' => (string) $action->id,
                'execution_plan_id' => (string) $action->execution_plan_id,
            ];
            foreach (ExecutionPlanFingerprint::ACTION_FIELDS as $field) {
                $payload[$field] = $action->getAttribute($field);
            }
            $payload['action_fingerprint'] = (string) $action->action_fingerprint;

            return $payload;
        })->sortBy('id')->values()->all();

        $manifest = [
            'version' => self::VERSION,
            'workspace_id' => (int) $plan->workspace_id,
            'execution_plan_id' => (string) $plan->id,
            'plan_hash' => (string) $plan->plan_hash,
            'objective_version_id' => (string) $plan->objective_version_id,
            'audience_snapshot_id' => (string) $plan->audience_snapshot_id,
            'gtm_experiment_id' => (string) $plan->gtm_experiment_id,
            'offer_version_id' => (string) $plan->offer_version_id,
            'conversion_definition_version_id' => (string) $plan->conversion_definition_version_id,
            'outreach_policy_version_id' => (string) $plan->outreach_policy_version_id,
            'counts' => [
                'arms' => (int) $plan->arm_count,
                'accounts' => (int) $plan->account_count,
                'people' => (int) $plan->people_count,
                'actions' => (int) $plan->action_count,
            ],
            'costs' => [
                'maximum_micros' => (int) $plan->maximum_cost_micros,
                'estimated_micros' => (int) $plan->estimated_cost_micros,
                'actual_micros' => (int) $plan->actual_cost_micros,
            ],
            'actions' => $actionPayloads,
        ];
        if ($plan->getAttribute('origin') !== ExecutionOrigin::Human) {
            return $manifest;
        }
        $run = ObjectiveRun::query()->where('workspace_id', $plan->workspace_id)->whereKey($plan->objective_run_id)->first();
        $composition = $run === null ? null : CampaignComposition::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($run->getAttribute('campaign_composition_id'))->first();
        $campaign = Campaign::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($plan->input_manifest['campaign_id'] ?? null)->first();
        $scope = $composition === null ? null : ExecutionAuthorityScope::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($composition->execution_authority_scope_id)->first();
        $drafts = ObjectiveRunArmDraft::query()->where('workspace_id', $plan->workspace_id)
            ->where('objective_run_id', $plan->objective_run_id)->orderBy('id')->get();

        $dailySendLimit = $campaign?->getAttribute('daily_send_limit');

        return [...$manifest,
            'version' => self::HUMAN_VERSION,
            ...($plan->schema_version === BuildHumanExecutionPlan::VERSION
                ? ['approval_lifetime' => 'unchanged_campaign_within_sending_allowance'] : []),
            'origin' => ExecutionOrigin::Human->value,
            'execution_authority' => $scope === null ? null : [
                'scope_id' => $scope->id, 'content_hash' => $scope->content_hash,
                'name' => $scope->name, 'authority' => $scope->authority,
            ],
            'target_contribution_count' => $composition?->target_contribution_count,
            ...($dailySendLimit === null ? [] : ['daily_send_limit' => (int) $dailySendLimit]),
            'campaign_window' => ['starts_at' => $composition?->sending_starts_at?->toAtomString(), 'ends_at' => $composition?->sending_ends_at?->toAtomString()],
            'authorship' => [
                'objective_run_id' => $plan->objective_run_id,
                'campaign_composition_id' => $run?->getAttribute('campaign_composition_id'),
                'run_input_hash' => $run?->input_hash,
                'drafts' => $drafts->map(fn ($draft) => [
                    'id' => $draft->id, 'origin' => $draft->getAttribute('origin')->value,
                    'author_id' => $draft->getAttribute('authored_by_user_id'),
                    'authored_at' => $draft->getAttribute('authored_at')?->toAtomString(),
                    'input_hash' => $draft->input_hash, 'output_hash' => $draft->output_hash,
                    'template_hash' => $draft->template_hash,
                ])->all(),
            ],
        ];
    }

    /** @param Collection<int, ExecutionPlanAction>|null $actions */
    public function hash(ExecutionPlan $plan, ?Collection $actions = null): string
    {
        return CanonicalObjectivePayload::hash($this->build($plan, $actions));
    }

    /** @param Collection<int, ExecutionPlanAction>|null $actions
     * @return list<string>
     */
    public function actionFingerprints(ExecutionPlan $plan, ?Collection $actions = null): array
    {
        $actions ??= $plan->actions()->orderBy('id')->get();

        return array_values($actions->pluck('action_fingerprint')->map(strval(...))->sort()->all());
    }
}
