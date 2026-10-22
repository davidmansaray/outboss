<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Services\CampaignSendingForecast;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Objectives\DTOs\ExactPlanValidation;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Domain\Objectives\Enums\ExecutionPlanStatus;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\AudienceSnapshotMember;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\ExecutionPlanCompilation;
use App\Models\ObjectiveRun;
use App\Models\Offer;
use App\Models\OutreachPolicy;
use App\Models\OutreachPolicyVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Read-only exact validation across human review, compilation and execution. */
final class ValidateHumanExecutionPlan
{
    public const VERSION = 'human_exact_plan_validator_v1';

    public function handle(ExecutionPlan $plan): ExactPlanValidation
    {
        return DB::transaction(function () use ($plan): ExactPlanValidation {
            $plan = ExecutionPlan::query()->where('workspace_id', $plan->workspace_id)->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            /** @var Collection<int, ExecutionPlanAction> $actions */
            $actions = $plan->actions()->where('workspace_id', $plan->workspace_id)->orderBy('id')->lockForUpdate()->get();
            try {
                return $this->validate($plan, $actions);
            } catch (ValidationException $error) {
                return $this->blocked($plan, $actions, $error->getMessage());
            } catch (SenderIdentityUnavailable $error) {
                return $this->blocked($plan, $actions, SenderIdentityUnavailable::USER_MESSAGE, 'sender_identity_changed');
            } catch (ModelNotFoundException|HttpException $error) {
                return $this->blocked($plan, $actions, 'A reviewed campaign dependency or its owner authorization is no longer available.');
            }
        }, 3);
    }

    /** @param Collection<int, ExecutionPlanAction> $actions */
    private function validate(ExecutionPlan $plan, Collection $actions): ExactPlanValidation
    {
        $compiled = in_array($plan->status, [ExecutionPlanStatus::Compiled, ExecutionPlanStatus::Executing], true);
        $actionStates = $compiled
            ? [ExecutionPlanActionState::Compiled, ExecutionPlanActionState::Queued, ExecutionPlanActionState::Attempted,
                ExecutionPlanActionState::Uncertain, ExecutionPlanActionState::ProviderAccepted, ExecutionPlanActionState::Sent]
            : [ExecutionPlanActionState::AwaitingApproval, ExecutionPlanActionState::Approved];
        if ($plan->getAttribute('origin') !== ExecutionOrigin::Human
            || ! in_array($plan->status, [ExecutionPlanStatus::AwaitingApproval, ExecutionPlanStatus::Approved, ExecutionPlanStatus::Compiled, ExecutionPlanStatus::Executing], true)
            || $actions->isEmpty()
            || $actions->contains(fn ($action): bool => ! in_array($action->state, $actionStates, true))) {
            return $this->blocked($plan, $actions, 'The human plan is not in a reviewable or executable state.', 'plan_state_invalid');
        }
        $workspaceId = (int) $plan->workspace_id;
        $run = ObjectiveRun::query()->where('workspace_id', $workspaceId)->whereKey($plan->objective_run_id)->lockForUpdate()->firstOrFail();
        $context = $run->objectiveVersion()->where('workspace_id', $workspaceId)->firstOrFail();
        $campaign = Campaign::query()->where('workspace_id', $workspaceId)->whereKey($plan->input_manifest['campaign_id'] ?? null)->lockForUpdate()->firstOrFail();
        $actor = User::query()->findOrFail($run->created_by_user_id);
        if ((! $compiled && ($campaign->getAttribute('status') !== CampaignStatus::Draft || $campaign->launched_at !== null))
            || ($compiled && ! in_array($campaign->getAttribute('status'), [CampaignStatus::Draft, CampaignStatus::Active, CampaignStatus::Paused, CampaignStatus::Completed], true))) {
            return $this->blocked($plan, $actions, 'The campaign lifecycle no longer matches this review.', 'plan_state_invalid');
        }
        if ($compiled) {
            $artifact = ExecutionPlanCompilation::query()->where('workspace_id', $workspaceId)
                ->where('execution_plan_id', $plan->id)->where('state', 'succeeded')->lockForUpdate()->sole();
            app(VerifyHumanCompilationArtifact::class)->handle($plan, $artifact);
        }
        $expectedRunHash = CanonicalObjectivePayload::hash([
            'schema' => 'human_campaign_run_v1', 'campaign_id' => $campaign->id,
            'campaign_composition_id' => $run->getAttribute('campaign_composition_id'),
            'context_id' => $context->id, 'context_hash' => $context->content_hash, 'author_id' => $actor->id,
        ]);
        if ($run->getAttribute('origin') !== ExecutionOrigin::Human || $run->getAttribute('gtm_experiment_id') !== null
            || $run->ai_budget_envelope_id !== null || $plan->getAttribute('gtm_experiment_id') !== null
            || ! hash_equals($expectedRunHash, $run->input_hash)
            || $run->objective_id !== $context->objective_id
            || $run->audience_snapshot_id !== $context->audience_snapshot_id
            || $plan->objective_version_id !== $context->id
            || $plan->audience_snapshot_id !== $context->audience_snapshot_id
            || $plan->offer_version_id !== $context->offer_version_id
            || $plan->conversion_definition_version_id !== $context->conversion_definition_version_id
            || $plan->outreach_policy_version_id !== ($context->authority_envelope['outreach_policy_version_id'] ?? null)
            || (int) $plan->created_by_user_id !== (int) $actor->id
            || (int) $plan->arm_count !== 0
            || (int) $plan->people_count !== $actions->pluck('person_id')->unique()->count()
            || (int) $plan->account_count !== $actions->pluck('account_id')->unique()->count()) {
            return $this->blocked($plan, $actions, 'The human review lineage or counts changed.', 'run_changed');
        }
        foreach (['maximum_cost_micros', 'actual_cost_micros'] as $field) {
            if ((int) $plan->getAttribute($field) !== 0 || (int) $run->getAttribute($field) !== 0) {
                return $this->blocked($plan, $actions, 'A human-authored review cannot authorize AI expenditure.', 'policy_cap_exceeded');
            }
        }
        // This reconstructs current context, authored bytes, source rights,
        // suppression, exact recipient identity and selected sender. Missing
        // immutable records are rejected, never regenerated during validation.
        $offer = Offer::query()->where('workspace_id', $workspaceId)->whereKey($context->offerVersion->offer_id)->lockForUpdate()->firstOrFail();
        $verified = app(BuildHumanExecutionPlan::class)->handle($campaign, $run, $actor, existingOnly: true, workspace: $campaign->workspace()->firstOrFail());
        if ($verified->id !== $plan->id) {
            return $this->blocked($plan, $actions, 'This is not the saved human review.', 'plan_hash_mismatch');
        }
        if (! $compiled) {
            $composition = CampaignComposition::query()->where('workspace_id', $workspaceId)
                ->whereKey($run->getAttribute('campaign_composition_id'))->firstOrFail();
            if ($composition->sending_ends_at !== null || $campaign->daily_send_limit !== null) {
                $forecast = app(CampaignSendingForecast::class)->handle($campaign, $composition);
                if (in_array($forecast['status'], ['over_capacity', 'unsupported', 'unavailable', 'daily_limit_exceeded'], true)) {
                    return $this->blocked($plan, $actions, (string) $forecast['message'], 'campaign_deadline_capacity');
                }
            }
        }
        $policy = OutreachPolicy::query()->where('workspace_id', $workspaceId)->where('status', 'active')->lockForUpdate()->firstOrFail();
        $policyVersion = OutreachPolicyVersion::query()->where('workspace_id', $workspaceId)->whereKey($plan->outreach_policy_version_id)->lockForUpdate()->firstOrFail();
        if ($policy->execution_paused_at !== null || $policy->current_version_id !== $policyVersion->id
            || ! in_array('prospecting', $policyVersion->getAttribute('allowed_source_purposes'), true)) {
            return $this->blocked($plan, $actions, 'The workspace outreach policy no longer permits this review.', 'policy_changed');
        }
        $hours = min((int) $policyVersion->approval_ttl_hours, (int) config('objectives.safety.approval_ttl_hours'));
        $wholeCampaign = $plan->schema_version === BuildHumanExecutionPlan::VERSION;
        $validUntil = $wholeCampaign ? $context->ends_at->copy() : now()->addHours(max(0, $hours))->min($context->ends_at);
        $readiness = $offer->currentReadinessEvaluation;
        if (! $wholeCampaign && $readiness?->valid_until !== null) {
            $validUntil = $validUntil->min($readiness->valid_until);
        }
        $sourceFacts = [];
        foreach ($actions as $action) {
            $member = AudienceSnapshotMember::query()->where('workspace_id', $workspaceId)
                ->where('audience_snapshot_id', $plan->audience_snapshot_id)->whereKey($action->audience_snapshot_member_id)->lockForUpdate()->firstOrFail();
            $sources = app(ValidateHumanAudienceSourceRights::class)->handle($member);
            foreach ($sources as $source) {
                foreach ([$source['membership_expires_at'], $source['snapshot_expires_at'], $source['policy']['policy_expires_at'] ?? null] as $expiry) {
                    if (is_string($expiry) && trim($expiry) !== '') {
                        $validUntil = $validUntil->min(Carbon::parse($expiry));
                    }
                }
            }
            $sourceFacts[(string) $action->id] = $sources;
        }
        if (! $validUntil->isFuture()) {
            return $this->blocked($plan, $actions, 'No safe approval lifetime remains. Refresh readiness and review.', 'approval_window_closed');
        }
        $campaignSettings = $campaign->only([
            'send_mode', 'timezone', 'send_window_start', 'send_window_end',
            'business_days_only', 'open_tracking_enabled',
        ]);
        if ($campaign->daily_send_limit !== null) {
            $campaignSettings['daily_send_limit'] = (int) $campaign->daily_send_limit;
        }
        $dependencyHash = CanonicalObjectivePayload::hash([
            'version' => self::VERSION, 'plan_hash' => $plan->plan_hash,
            'run_input_hash' => $run->input_hash, 'context_hash' => $context->content_hash,
            'policy_content_hash' => $policyVersion->content_hash, 'source_facts' => $sourceFacts,
            ...($wholeCampaign ? ['approval_lifetime' => 'unchanged_campaign', 'offer_content_hash' => $context->offerVersion->content_hash]
                : ['offer_readiness_id' => $readiness?->id]),
            'campaign_settings' => $campaignSettings,
        ]);
        $hashes = [];
        foreach ($actions as $action) {
            $hashes[(string) $action->id] = CanonicalObjectivePayload::hash([
                'version' => self::VERSION, 'plan_hash' => $plan->plan_hash,
                'action_fingerprint' => $action->action_fingerprint, 'dependency_hash' => $dependencyHash,
            ]);
        }

        return new ExactPlanValidation([], [], $hashes, $dependencyHash, (string) $policyVersion->id, $validUntil, $actions);
    }

    /** @param Collection<int, ExecutionPlanAction> $actions */
    private function blocked(ExecutionPlan $plan, Collection $actions, string $message, string $code = 'policy_input_changed'): ExactPlanValidation
    {
        return new ExactPlanValidation([['code' => $code, 'message' => $message]], [], [], '', (string) $plan->outreach_policy_version_id, null, $actions);
    }
}
