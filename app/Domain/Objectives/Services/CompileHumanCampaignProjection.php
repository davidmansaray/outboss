<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Campaigns\DTOs\GovernedCampaignAction;
use App\Domain\Campaigns\Services\AdoptHumanCampaignDraft;
use App\Domain\Campaigns\Services\GovernedCampaignCompilationCapability;
use App\Domain\Objectives\Enums\ExecutionActionEventType;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\ExecutionPlanActionCompilation;
use App\Models\ExecutionPlanCampaign;
use App\Models\ExecutionPlanCompilation;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Project an already revalidated approval into its original human campaign. */
final class CompileHumanCampaignProjection
{
    /**
     * @param  Collection<int, ExecutionPlanAction>  $actions
     * @return array{ObjectiveRun, list<array<string, mixed>>}
     */
    public function handle(ExecutionPlan $plan, Collection $actions, ExecutionPlanCompilation $compilation, GovernedCampaignCompilationCapability $capability): array
    {
        $workspaceId = (int) $plan->workspace_id;
        $run = ObjectiveRun::query()->where('workspace_id', $workspaceId)->whereKey($plan->objective_run_id)->lockForUpdate()->firstOrFail();
        $composition = CampaignComposition::query()->where('workspace_id', $workspaceId)->whereKey($run->getAttribute('campaign_composition_id'))->firstOrFail();
        $campaign = Campaign::query()->where('workspace_id', $workspaceId)->whereKey($composition->campaign_id)->lockForUpdate()->firstOrFail();
        $draft = ObjectiveRunArmDraft::query()->where('workspace_id', $workspaceId)->where('objective_run_id', $run->id)->sole();
        $messages = $draft->claim_manifest['members'] ?? null;
        if (! is_array($messages)) {
            throw ValidationException::withMessages(['campaign' => 'The authored recipient manifest is missing.']);
        }
        $members = collect($messages)->keyBy('person_id');
        $recipients = $campaign->recipients()->where('workspace_id', $workspaceId)->humanReviewAudience()->lockForUpdate()->get()->keyBy('person_id');
        $projections = [];
        foreach ($actions as $action) {
            $message = $members->get($action->person_id);
            $recipient = $recipients->get($action->person_id);
            if ($message === null || $recipient === null || $action->objective_run_arm_draft_id !== $draft->id
                || $message['recipient_id'] !== $recipient->id || $recipient->sendAttempts()->exists()) {
                throw ValidationException::withMessages(['campaign' => 'The exact human recipient and authored message mapping changed.']);
            }
            $projections[] = new GovernedCampaignAction(
                actionId: (string) $action->id, personId: (int) $action->person_id, accountId: (int) $action->account_id,
                mailboxId: (int) $action->mailbox_id, emailAddress: $action->destination_email_address,
                emailNormalized: $action->destination_email_address, fullName: $recipient->full_name,
                firstName: (string) $recipient->first_name, company: (string) $recipient->company,
                subjectTemplate: $draft->subject_template, bodyTemplate: $draft->body_template,
                subject: $action->subject, bodyText: $action->body_text,
                actionFingerprint: $action->action_fingerprint, contentHash: $action->content_hash,
            );
        }
        $result = app(AdoptHumanCampaignDraft::class)->handle($campaign, $composition, $projections, $capability);
        $link = ExecutionPlanCampaign::query()->create([
            'id' => (string) Str::uuid(), 'workspace_id' => $workspaceId,
            'execution_plan_compilation_id' => $compilation->id, 'execution_plan_id' => $plan->id,
            'gtm_experiment_arm_id' => null, 'campaign_id' => $campaign->id,
            'sequence_version_id' => $result->sequenceVersion->id, 'sequence_step_id' => $result->sequenceStep->id,
        ]);
        $manifest = [];
        foreach ($actions as $action) {
            $recipient = $result->recipientsByActionId[(string) $action->id];
            $actionLink = ExecutionPlanActionCompilation::query()->create([
                'id' => (string) Str::uuid(), 'workspace_id' => $workspaceId,
                'execution_plan_compilation_id' => $compilation->id, 'execution_plan_id' => $plan->id,
                'execution_plan_action_id' => $action->id, 'execution_plan_campaign_id' => $link->id,
                'campaign_id' => $campaign->id, 'campaign_recipient_id' => $recipient->id,
                'sequence_step_id' => $result->sequenceStep->id,
                'compiled_input_hash' => $action->action_fingerprint, 'rendered_content_hash' => $action->content_hash,
            ]);
            app(RecordExecutionActionEvent::class)->handle($action, ExecutionActionEventType::Compiled, $recipient);
            $manifest[] = [
                'action_id' => (string) $action->id, 'action_compilation_id' => (string) $actionLink->id,
                'campaign_recipient_id' => (int) $recipient->id,
                'compiled_input_hash' => $action->action_fingerprint, 'rendered_content_hash' => $action->content_hash,
            ];
        }
        $capability->seal($result->campaign);

        return [$run, [[
            'arm_id' => null, 'execution_plan_campaign_id' => (string) $link->id,
            'campaign_id' => (int) $campaign->id, 'sequence_version_id' => (int) $result->sequenceVersion->id,
            'sequence_step_id' => (int) $result->sequenceStep->id, 'actions' => $manifest,
        ]]];
    }
}
