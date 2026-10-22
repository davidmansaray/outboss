<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\CampaignRecipient;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanActionCompilation;
use App\Models\ExecutionPlanCampaign;
use App\Models\ExecutionPlanCompilation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Artifact integrity only: a successful replay does not renew send authority. */
final class VerifyHumanCompilationArtifact
{
    public function handle(ExecutionPlan $plan, ExecutionPlanCompilation $compilation): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Human compilation verification requires the enclosing plan transaction.');
        }
        // A competing compiler may commit while this request waits for the plan
        // lock. Use current reads, never an earlier REPEATABLE READ snapshot.
        $workspaceId = (int) $plan->workspace_id;
        $campaigns = ExecutionPlanCampaign::query()->where('workspace_id', $workspaceId)
            ->where('execution_plan_compilation_id', $compilation->id)->where('execution_plan_id', $plan->id)->lockForUpdate()->get();
        if ($campaigns->count() !== 1) {
            $this->reject();
        }
        $campaign = $campaigns->sole();
        if ((int) $campaign->campaign_id !== (int) ($plan->input_manifest['campaign_id'] ?? 0)
            || $campaign->getAttribute('gtm_experiment_arm_id') !== null) {
            $this->reject();
        }
        $actions = $plan->actions()->where('workspace_id', $workspaceId)->orderBy('id')->lockForUpdate()->get();
        $links = ExecutionPlanActionCompilation::query()->where('workspace_id', $workspaceId)
            ->where('execution_plan_compilation_id', $compilation->id)->lockForUpdate()->get();
        if ($links->count() !== $actions->count() || $actions->isEmpty()) {
            $this->reject();
        }
        $manifest = [];
        $recipientIds = [];
        foreach ($actions as $action) {
            $matching = $links->where('execution_plan_action_id', $action->id);
            if ($matching->count() !== 1) {
                $this->reject();
            }
            $link = $matching->sole();
            $recipient = CampaignRecipient::query()->where('workspace_id', $workspaceId)
                ->where('campaign_id', $campaign->campaign_id)->whereKey($link->campaign_recipient_id)->lockForUpdate()->first();
            if ($link->execution_plan_id !== $plan->id || $link->execution_plan_campaign_id !== $campaign->id
                || (int) $link->campaign_id !== (int) $campaign->campaign_id
                || (int) $link->sequence_step_id !== (int) $campaign->sequence_step_id
                || ! hash_equals($link->compiled_input_hash, $action->action_fingerprint)
                || ! hash_equals($link->rendered_content_hash, $action->content_hash)
                || $recipient === null || (int) $recipient->person_id !== (int) $action->person_id
                || (int) $recipient->account_id !== (int) $action->account_id
                || (int) $recipient->sequence_version_id !== (int) $campaign->sequence_version_id
                || $recipient->email_normalized !== $action->destination_email_address
                || $recipient->governed_subject_snapshot !== $action->subject
                || $recipient->governed_body_text_snapshot !== $action->body_text) {
                $this->reject();
            }
            $recipientIds[] = $recipient->id;
            $manifest[] = [
                'action_id' => (string) $action->id, 'action_compilation_id' => (string) $link->id,
                'campaign_recipient_id' => (int) $recipient->id,
                'compiled_input_hash' => $action->action_fingerprint, 'rendered_content_hash' => $action->content_hash,
            ];
        }
        if (count(array_unique($recipientIds)) !== count($recipientIds)) {
            $this->reject();
        }
        $campaignManifest = [[
            'arm_id' => null, 'execution_plan_campaign_id' => (string) $campaign->id,
            'campaign_id' => (int) $campaign->campaign_id, 'sequence_version_id' => (int) $campaign->sequence_version_id,
            'sequence_step_id' => (int) $campaign->sequence_step_id, 'actions' => $manifest,
        ]];
        $outputHash = CanonicalObjectivePayload::hash([
            'version' => CompileExecutionPlan::VERSION, 'workspace_id' => $workspaceId,
            'execution_plan_id' => (string) $plan->id, 'input_hash' => $compilation->input_hash,
            'campaigns' => $campaignManifest,
        ]);
        $expected = [
            'version' => 'execution_plan_compilation_v1', 'compilation_id' => (string) $compilation->id,
            'input_hash' => $compilation->input_hash, 'output_hash' => $outputHash, 'campaigns' => $campaignManifest,
        ];
        if (! hash_equals((string) $compilation->output_hash, $outputHash)
            || ! hash_equals(CanonicalObjectivePayload::hash($plan->compilation_manifest), CanonicalObjectivePayload::hash($expected))) {
            $this->reject();
        }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['plan' => 'The compiled human campaign lineage or exact message snapshot is incomplete or changed.']);
    }
}
