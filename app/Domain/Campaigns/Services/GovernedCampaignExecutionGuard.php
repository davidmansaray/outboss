<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\DTOs\GovernedCampaignCheckpoint;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\PolicyEvaluationStage;
use App\Domain\Objectives\Services\GovernedExecutionCheckpoint;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\CampaignRecipient;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\ExecutionPlanActionCompilation;
use App\Models\ExecutionPlanCampaign;
use App\Models\SequenceStep;

/** Resolve compiled lineage and run the public exact-approval checkpoint. */
final class GovernedCampaignExecutionGuard
{
    public function __construct(private readonly GovernedExecutionCheckpoint $checkpoint) {}

    public function forCampaign(Campaign $campaign, PolicyEvaluationStage $stage): GovernedCampaignCheckpoint
    {
        if (! $this->hasGovernedIndicator($campaign)) {
            return GovernedCampaignCheckpoint::generic();
        }

        $mapping = $this->campaignMapping($campaign);
        if (! $mapping instanceof ExecutionPlanCampaign) {
            return $this->missing();
        }
        $plan = ExecutionPlan::query()
            ->where('workspace_id', $campaign->getAttribute('workspace_id'))
            ->whereKey($mapping->execution_plan_id)
            ->first();
        if (! $plan instanceof ExecutionPlan
            || (int) $mapping->workspace_id !== (int) $campaign->getAttribute('workspace_id')
            || (int) $mapping->campaign_id !== (int) $campaign->id
            || ! hash_equals((string) $mapping->execution_plan_id, (string) $plan->id)) {
            return $this->missing();
        }

        $recipients = CampaignRecipient::query()
            ->where('workspace_id', $campaign->getAttribute('workspace_id'))
            ->where('campaign_id', $campaign->id)
            ->when($plan->getAttribute('origin') === ExecutionOrigin::Human, fn ($query) => $query->humanReviewAudience())
            ->count();
        $links = ExecutionPlanActionCompilation::query()
            ->where('workspace_id', $campaign->getAttribute('workspace_id'))
            ->where('campaign_id', $campaign->id)
            ->where('execution_plan_campaign_id', $mapping->id)
            ->where('execution_plan_id', $plan->id)
            ->count();
        if ($recipients < 1 || $links !== $recipients) {
            return $this->missing();
        }

        $approval = $this->checkpoint->forPlan($plan, $stage);

        return new GovernedCampaignCheckpoint(
            governed: true,
            blockers: $approval->blockers,
            approval: $approval,
        );
    }

    public function forRecipient(
        CampaignRecipient $recipient,
        PolicyEvaluationStage $stage,
        ?SequenceStep $step = null,
    ): GovernedCampaignCheckpoint {
        $campaign = Campaign::query()
            ->where('workspace_id', $recipient->getAttribute('workspace_id'))
            ->whereKey($recipient->campaign_id)
            ->first();
        if (! $campaign instanceof Campaign) {
            return $this->missing();
        }
        if (! $this->hasGovernedIndicator($campaign, $recipient)) {
            return GovernedCampaignCheckpoint::generic();
        }

        $mapping = $this->campaignMapping($campaign);
        $links = ExecutionPlanActionCompilation::query()
            ->where('workspace_id', $recipient->getAttribute('workspace_id'))
            ->where('campaign_id', $campaign->id)
            ->where('campaign_recipient_id', $recipient->id)
            ->get();
        if (! $mapping instanceof ExecutionPlanCampaign || $links->count() !== 1) {
            return $this->missing();
        }
        /** @var ExecutionPlanActionCompilation $link */
        $link = $links->first();
        $action = ExecutionPlanAction::query()
            ->where('workspace_id', $recipient->getAttribute('workspace_id'))
            ->whereKey($link->execution_plan_action_id)
            ->first();
        $stepId = $step instanceof SequenceStep ? $step->id : $recipient->next_sequence_step_id;
        if (! $action instanceof ExecutionPlanAction
            || (int) $mapping->workspace_id !== (int) $recipient->getAttribute('workspace_id')
            || (int) $mapping->campaign_id !== (int) $campaign->id
            || ! hash_equals((string) $mapping->id, (string) $link->execution_plan_campaign_id)
            || ! hash_equals((string) $mapping->execution_plan_id, (string) $link->execution_plan_id)
            || ! hash_equals((string) $action->execution_plan_id, (string) $link->execution_plan_id)
            || (int) $link->campaign_recipient_id !== (int) $recipient->id
            || (int) $link->sequence_step_id !== (int) $stepId
            || ! hash_equals((string) $link->compiled_input_hash, (string) $action->action_fingerprint)
            || ! hash_equals((string) $link->rendered_content_hash, (string) $action->content_hash)) {
            return $this->missing();
        }

        $approval = $this->checkpoint->forAction($action, $stage);

        return new GovernedCampaignCheckpoint(
            governed: true,
            blockers: $approval->blockers,
            action: $action,
            approval: $approval,
        );
    }

    private function hasGovernedIndicator(Campaign $campaign, ?CampaignRecipient $recipient = null): bool
    {
        if (CampaignComposition::query()->where('campaign_id', $campaign->id)->exists()
            || ExecutionPlanCampaign::query()->where('campaign_id', $campaign->id)->exists()
            || ExecutionPlanActionCompilation::query()->where('campaign_id', $campaign->id)->exists()) {
            return true;
        }

        if ($recipient instanceof CampaignRecipient) {
            return $recipient->governed_subject_snapshot !== null
                || $recipient->governed_body_text_snapshot !== null;
        }

        return CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where(function ($query): void {
                $query->whereNotNull('governed_subject_snapshot')
                    ->orWhereNotNull('governed_body_text_snapshot');
            })->exists();
    }

    private function campaignMapping(Campaign $campaign): ?ExecutionPlanCampaign
    {
        $mappings = ExecutionPlanCampaign::query()->where('campaign_id', $campaign->id)->get();

        return $mappings->count() === 1 ? $mappings->first() : null;
    }

    private function missing(): GovernedCampaignCheckpoint
    {
        return new GovernedCampaignCheckpoint(true, [[
            'code' => 'governed_action_missing',
            'message' => 'This campaign needs exact message review and approval before it can send. Open Review exact messages to continue. If it was already approved, its saved sending details no longer match that approval.',
        ]]);
    }
}
