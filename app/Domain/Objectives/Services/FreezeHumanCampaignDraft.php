<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Enums\SequenceVersionStatus;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ObjectiveRunArmDraftState;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\DeterministicMessageTemplate;
use App\Domain\Objectives\Support\HumanMessageContract;
use App\Domain\Objectives\Support\MessageEvidenceContract;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\ExecutionPlanCompilation;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use App\Models\SequenceVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Freeze authored bytes; the enclosing preparation transaction also creates the exact plan. */
final class FreezeHumanCampaignDraft
{
    public const SCHEMA = 'human_campaign_draft_v1';

    public function handle(Campaign $campaign, ObjectiveRun $run, User $actor, bool $existingOnly = false, ?Workspace $workspace = null): ObjectiveRunArmDraft
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Human draft freezing requires the preparation transaction.');
        }
        $workspace ??= $actor->currentWorkspace();
        abort_unless((int) $campaign->getAttribute('workspace_id') === (int) $workspace->id, 404);
        abort_unless($workspace->isOwner($actor), 403);
        $campaign = Campaign::query()->where('workspace_id', $workspace->id)->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
        $run = ObjectiveRun::query()->where('workspace_id', $workspace->id)->whereKey($run->id)->lockForUpdate()->firstOrFail();
        if ($run->getAttribute('origin') !== ExecutionOrigin::Human || (int) $run->created_by_user_id !== (int) $actor->id) {
            $this->reject('Use a human run prepared by the current owner.');
        }
        $compiled = $existingOnly && ExecutionPlanCompilation::query()->where('workspace_id', $workspace->id)
            ->where('state', 'succeeded')->whereHas('executionPlan', fn ($query) => $query->where('objective_run_id', $run->id))->exists();
        $states = $compiled ? [ObjectiveRunState::Waiting, ObjectiveRunState::Running] : [ObjectiveRunState::Planned, ObjectiveRunState::NeedsReview];
        if (! in_array($run->state, $states, true)) {
            $this->reject('Only a planned or reviewable human run can freeze copy.');
        }
        $composition = CampaignComposition::query()->where('workspace_id', $workspace->id)->where('campaign_id', $campaign->id)
            ->whereKey($run->getAttribute('campaign_composition_id'))->firstOrFail();
        $context = $run->objectiveVersion()->firstOrFail();
        $verified = app(FreezeHumanCampaignContext::class)->handle($campaign, $composition, $context->audienceSnapshot, $actor, $existingOnly, $workspace);
        if ($verified->id !== $context->id) {
            $this->reject('The frozen campaign context changed.');
        }
        $sequence = SequenceVersion::query()->where('campaign_id', $campaign->id)
            ->whereKey($campaign->draft_sequence_version_id ?? $campaign->published_sequence_version_id)->lockForUpdate()->firstOrFail();
        $steps = $sequence->activeSteps()->lockForUpdate()->get();
        $sequenceStates = $compiled ? [SequenceVersionStatus::Draft, SequenceVersionStatus::Published] : [SequenceVersionStatus::Draft];
        if (! in_array($sequence->getAttribute('status'), $sequenceStates, true) || $steps->count() !== 1) {
            $this->reject('Prepare exactly one draft email for the first human campaign slice.');
        }
        $step = $steps->sole();
        if ($step->subject_spintax_enabled || $step->body_spintax_enabled) {
            $this->reject('Turn off message variations before exact review.');
        }
        if ($step->getAttribute('send_as') !== SequenceSendAs::NewThread || (int) $step->delay_minutes !== 0 || (int) $step->position !== 1) {
            $this->reject('Use one immediate new-thread message for the first human campaign slice.');
        }
        $existingPlan = $run->executionPlans()->where('workspace_id', $workspace->id)->first();
        $offer = app(BuildObjectiveDraftPrompt::class)->offerContract($context->offerVersion,
            recheckReadiness: $existingPlan === null || $existingPlan->schema_version === BuildHumanExecutionPlan::VERSION);
        $members = $context->audienceSnapshot->members()->where('result', 'included')->get()->keyBy('person_id');
        $recipients = $campaign->recipients()->humanReviewAudience()->orderBy('id')->lockForUpdate()->get();
        if ($recipients->isEmpty() || $recipients->count() !== $members->count()) {
            $this->reject('Freeze a nonempty exact audience before preparing copy.');
        }
        $messages = [];
        foreach ($recipients as $recipient) {
            app(AssertHumanRecipientContactable::class)->handle($recipient);
            if (! $compiled && $recipient->sendAttempts()->exists()) {
                $this->reject('A selected recipient already has a send attempt. Resolve its delivery status before preparing another review.');
            }
            $member = $members->get($recipient->person_id);
            if ($member === null || (! $compiled && ($recipient->getAttribute('status') !== RecipientStatus::Queued
                || $recipient->first_sent_at !== null || $recipient->send_lease_token !== null))
                || (int) $recipient->account_id !== (int) $member->account_id) {
                $this->reject('Every selected recipient must still match the frozen queued audience.');
            }
            app(ValidateHumanAudienceSourceRights::class)->handle($member);
            $evidence = [];
            foreach ($member->evidence_manifest as $claim) {
                $current = app(MessageEvidenceContract::class)->verifyFrozen($claim, (int) $workspace->id, (int) $member->account_id);
                if ($current !== null) {
                    $evidence[] = $current;
                }
            }
            // Optional research is retained only when it is still safe and current.
            // Authorship and exact approval, not research, authorize human copy.
            $personalization = app(DeterministicMessageTemplate::class)->recipientSnapshot($recipient->first_name, $recipient->full_name, (string) $recipient->company);
            $rendered = app(HumanMessageContract::class)->build($step->subject_template, $step->body_template, $personalization, $offer['destination_url'], [
                'composition_id' => $composition->id, 'goal_version_id' => $composition->objective_goal_version_id,
                'offer_version_id' => $context->offer_version_id, 'offer_content_hash' => $offer['content_hash'],
                'author_id' => (int) $actor->id, 'evidence_manifest_hash' => CanonicalObjectivePayload::hash($evidence),
            ]);
            $messages[] = [
                'recipient_id' => $recipient->id, 'snapshot_member_id' => $member->id, 'person_id' => $recipient->person_id,
                'account_id' => $recipient->account_id, 'personalization' => $personalization,
                'evidence' => $evidence, 'rendered' => $rendered,
            ];
        }
        $manifest = ['schema' => self::SCHEMA, 'composition_id' => $composition->id, 'context_hash' => $context->content_hash,
            'sequence_version_id' => $sequence->id, 'sequence_step_id' => $step->id, 'members' => $messages];
        $inputHash = CanonicalObjectivePayload::hash(['run_input_hash' => $run->input_hash, 'author_id' => $actor->id,
            'subject_template' => $step->subject_template, 'body_template' => $step->body_template, 'manifest' => $manifest, 'offer' => $offer]);
        $existing = ObjectiveRunArmDraft::query()->where('workspace_id', $workspace->id)->where('objective_run_id', $run->id)->lockForUpdate()->first();
        if ($existing !== null) {
            if ($existing->getAttribute('origin') !== ExecutionOrigin::Human || ! hash_equals($existing->input_hash, $inputHash)
                || $existing->state !== ObjectiveRunArmDraftState::Succeeded
                || (int) $existing->getAttribute('authored_by_user_id') !== (int) $actor->id || $existing->getAttribute('authored_at') === null
                || $existing->schema_version !== self::SCHEMA || $existing->validator_version !== HumanMessageContract::VERSION
                || $existing->subject_template !== $step->subject_template || $existing->body_template !== $step->body_template
                || ! hash_equals(CanonicalObjectivePayload::hash($existing->claim_manifest ?? []), CanonicalObjectivePayload::hash($manifest))
                || ! hash_equals(CanonicalObjectivePayload::hash($existing->cta_manifest ?? []), CanonicalObjectivePayload::hash($offer))
                || ! hash_equals((string) $existing->output_hash, CanonicalObjectivePayload::hash($messages))
                || ! hash_equals((string) $existing->template_hash, $messages[0]['rendered']['template_hash'])) {
                $this->reject('This run already has different frozen copy. Prepare a new revision instead of overwriting it.');
            }

            return $existing;
        }

        if ($existingOnly) {
            $this->reject('The saved human message draft is missing. Prepare a new review.');
        }

        return ObjectiveRunArmDraft::query()->create([
            'workspace_id' => $workspace->id, 'objective_run_id' => $run->id, 'origin' => ExecutionOrigin::Human,
            'gtm_experiment_arm_id' => null, 'ai_budget_run_id' => null, 'ai_provider_operation_id' => null,
            'model_id' => null, 'prompt_version' => null, 'task_policy_version' => null,
            'state' => ObjectiveRunArmDraftState::Succeeded, 'schema_version' => self::SCHEMA, 'validator_version' => HumanMessageContract::VERSION,
            'input_hash' => $inputHash, 'subject_template' => $step->subject_template, 'body_template' => $step->body_template,
            'claim_manifest' => $manifest, 'cta_manifest' => $offer, 'output_hash' => CanonicalObjectivePayload::hash($messages),
            'template_hash' => $messages[0]['rendered']['template_hash'], 'authored_by_user_id' => $actor->id,
            'authored_at' => now(), 'started_at' => now(), 'finished_at' => now(),
        ]);
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['message' => $message]);
    }
}
