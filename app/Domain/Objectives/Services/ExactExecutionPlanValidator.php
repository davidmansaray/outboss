<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Domain\Conversions\Enums\ConversionDefinitionStatus;
use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\Objectives\DTOs\ExactPlanValidation;
use App\Domain\Objectives\Enums\AudienceMemberResult;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ExecutionPlanStatus;
use App\Domain\Objectives\Enums\GtmExperimentStatus;
use App\Domain\Objectives\Enums\ObjectiveRunArmDraftState;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Domain\Objectives\Enums\OutreachPolicyStatus;
use App\Domain\Objectives\Enums\StrategyStatus;
use App\Domain\Objectives\Support\ApprovalManifest;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\DeterministicMessageTemplate;
use App\Domain\Objectives\Support\ExecutionPlanFingerprint;
use App\Domain\Objectives\Support\MessageEvidenceContract;
use App\Models\Account;
use App\Models\AudienceSnapshot;
use App\Models\AudienceSnapshotMember;
use App\Models\ConversionDefinition;
use App\Models\ExecutionActionEvent;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\GtmExperiment;
use App\Models\GtmExperimentArm;
use App\Models\GtmExperimentAssignment;
use App\Models\GtmExperimentAssignmentMember;
use App\Models\Mailbox;
use App\Models\Objective;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use App\Models\ObjectiveVersion;
use App\Models\Offer;
use App\Models\OutreachPolicy;
use App\Models\OutreachPolicyVersion;
use App\Models\Person;
use App\Models\ProviderConnection;
use App\Models\ReasonToContactClaim;
use App\Models\ReasonToContactVersion;
use App\Models\Strategy;
use App\Models\StrategyVersion;
use App\Models\SuppressionEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reconstruct every mutable fact behind an exact plan.
 *
 * This service intentionally has no approval side effects. Callers hold a
 * transaction and choose whether to record a policy evaluation, grant, or
 * terminal approval event from the returned stable blockers and hashes.
 */
final class ExactExecutionPlanValidator
{
    public const VERSION = 'exact_execution_plan_validator_v1';

    public function __construct(
        private readonly ObjectiveReadiness $readiness,
        private readonly MailboxSenderIdentityService $senderIdentities,
        private readonly DeterministicMessageTemplate $templates,
        private readonly MessageEvidenceContract $evidence,
        private readonly RenderSequenceMessage $renderer,
        private readonly BuildObjectiveDraftPrompt $prompts,
        private readonly ApprovalManifest $manifest,
    ) {}

    public function handle(ExecutionPlan $plan, bool $lock = true): ExactPlanValidation
    {
        $plan = $this->planQuery($plan, $lock)->firstOrFail();
        if ($plan->getAttribute('origin') === ExecutionOrigin::Human) {
            return app(ValidateHumanExecutionPlan::class)->handle($plan);
        }
        if ($plan->getAttribute('origin') !== ExecutionOrigin::Ai) {
            return new ExactPlanValidation(
                [['code' => 'human_plan_validation_required', 'message' => 'This human plan requires its dedicated provenance and campaign-context validation.']],
                [], [], '', (string) $plan->outreach_policy_version_id, null, new Collection,
            );
        }
        /** @var Collection<int, ExecutionPlanAction> $actions */
        $actions = $this->lock(
            ExecutionPlanAction::query()
                ->where('workspace_id', $plan->workspace_id)
                ->where('execution_plan_id', $plan->id)
                ->orderBy('id'),
            $lock,
        )->get();
        $blockers = [];
        $warnings = [];
        $facts = [
            'validator_version' => self::VERSION,
            'workspace_id' => (int) $plan->workspace_id,
            'execution_plan_id' => (string) $plan->id,
            'plan_hash' => (string) $plan->plan_hash,
        ];

        if (! in_array($plan->status, [
            ExecutionPlanStatus::AwaitingApproval,
            ExecutionPlanStatus::Approved,
            ExecutionPlanStatus::Compiled,
            ExecutionPlanStatus::Executing,
        ], true)) {
            $this->block($blockers, 'plan_state_invalid', 'This plan is not in an approvable or executable state.');
        }
        if ($actions->isEmpty() || $actions->count() !== (int) $plan->action_count) {
            $this->block($blockers, 'action_set_changed', 'The exact action count no longer matches the plan.');
        }

        $payloads = $this->manifest->build($plan, $actions)['actions'];
        foreach ($payloads as $payload) {
            if (! is_array($payload)
                || ! hash_equals((string) ($payload['action_fingerprint'] ?? ''), ExecutionPlanFingerprint::action($payload))) {
                $this->block(
                    $blockers,
                    'action_hash_mismatch',
                    'An exact action fingerprint no longer matches its frozen bytes.',
                    (string) ($payload['id'] ?? ''),
                );
            }
        }
        if (! hash_equals(
            (string) $plan->plan_hash,
            ExecutionPlanFingerprint::plan((string) $plan->id, (string) $plan->canonical_payload_hash, $payloads),
        )) {
            $this->block($blockers, 'plan_hash_mismatch', 'The plan hash no longer matches the exact action set.');
        }

        $run = $this->lock(ObjectiveRun::query()
            ->where('workspace_id', $plan->workspace_id)
            ->whereKey($plan->objective_run_id), $lock)->first();
        $objective = $run instanceof ObjectiveRun
            ? $this->lock(Objective::query()
                ->where('workspace_id', $plan->workspace_id)
                ->whereKey($run->objective_id), $lock)->first()
            : null;
        $version = $this->lock(ObjectiveVersion::query()
            ->where('workspace_id', $plan->workspace_id)
            ->whereKey($plan->objective_version_id), $lock)->first();
        if (! $run instanceof ObjectiveRun
            || $run->getAttribute('origin') !== $plan->getAttribute('origin')
            || ! $objective instanceof Objective
            || ! $version instanceof ObjectiveVersion
            || ! hash_equals((string) $run->objective_version_id, (string) $plan->objective_version_id)
            || ! hash_equals((string) $run->audience_snapshot_id, (string) $plan->audience_snapshot_id)
            || ! hash_equals((string) $run->gtm_experiment_id, (string) $plan->gtm_experiment_id)
            || in_array($run->state, [ObjectiveRunState::Cancelled, ObjectiveRunState::Superseded, ObjectiveRunState::Failed], true)) {
            $this->block($blockers, 'run_changed', 'The exact Objective Run is no longer eligible.');
        }
        if (! $objective instanceof Objective
            || ! hash_equals((string) ($objective->current_plan_id ?? ''), (string) $plan->id)) {
            $this->block($blockers, 'plan_superseded', 'This is no longer the Objective current plan.');
        }
        if (! $objective instanceof Objective
            || ! $version instanceof ObjectiveVersion
            || ! hash_equals((string) ($objective->current_version_id ?? ''), (string) $version->id)) {
            $this->block($blockers, 'objective_version_changed', 'The Objective now points to a different version.');
        }
        if ($run instanceof ObjectiveRun
            && ! hash_equals((string) ($plan->input_manifest['run_input_hash'] ?? ''), (string) $run->input_hash)) {
            $this->block($blockers, 'run_input_changed', 'The plan no longer matches the Objective Run input hash.');
        }

        $readinessHash = '';
        if ($objective instanceof Objective) {
            $readiness = $this->readiness->handle($objective);
            $readinessHash = $readiness->inputHash;
            foreach ($readiness->blockers as $blocker) {
                $this->block(
                    $blockers,
                    $blocker['code'],
                    $blocker['message'],
                );
            }
            foreach ($readiness->warnings as $warning) {
                $this->warn(
                    $warnings,
                    $warning['code'],
                    $warning['message'],
                );
            }
        }

        $policy = $this->lock(OutreachPolicy::query()
            ->where('workspace_id', $plan->workspace_id), $lock)->first();
        $policyVersion = $this->lock(OutreachPolicyVersion::query()
            ->where('workspace_id', $plan->workspace_id)
            ->whereKey($plan->outreach_policy_version_id), $lock)->first();
        if (! $policy instanceof OutreachPolicy
            || ! $policyVersion instanceof OutreachPolicyVersion
            || $this->value($policy->status) !== OutreachPolicyStatus::Active->value
            || $policy->execution_paused_at !== null
            || ! hash_equals((string) ($policy->current_version_id ?? ''), (string) $policyVersion->id)) {
            $this->block(
                $blockers,
                $policy instanceof OutreachPolicy && $policy->execution_paused_at !== null
                    ? 'workspace_outreach_paused'
                    : 'policy_changed',
                'The current workspace outreach policy does not match this plan.',
            );
        }
        if ($policyVersion instanceof OutreachPolicyVersion
            && ((int) $plan->people_count > min((int) $policyVersion->maximum_people, (int) config('objectives.safety.maximum_people'))
                || (int) $plan->account_count > min((int) $policyVersion->maximum_accounts, (int) config('objectives.safety.maximum_accounts'))
                || (int) $plan->action_count > min((int) $policyVersion->maximum_messages, (int) config('objectives.safety.maximum_messages'))
                || (int) $plan->maximum_cost_micros > min((int) $policyVersion->maximum_ai_cost_micros, (int) config('objectives.safety.maximum_ai_cost_micros')))) {
            $this->block($blockers, 'policy_cap_exceeded', 'The plan exceeds a current server or workspace policy cap.');
        }

        $snapshot = $this->lock(AudienceSnapshot::query()
            ->where('workspace_id', $plan->workspace_id)
            ->whereKey($plan->audience_snapshot_id), $lock)->first();
        if (! $snapshot instanceof AudienceSnapshot || $snapshot->status !== 'frozen') {
            $this->block($blockers, 'audience_changed', 'The frozen audience is no longer available.');
        }
        $experiment = $this->lock(GtmExperiment::query()
            ->where('workspace_id', $plan->workspace_id)
            ->whereKey($plan->gtm_experiment_id), $lock)->first();
        if (! $experiment instanceof GtmExperiment
            || ! hash_equals((string) $experiment->objective_version_id, (string) $plan->objective_version_id)
            || ! hash_equals((string) $experiment->audience_snapshot_id, (string) $plan->audience_snapshot_id)
            || ! in_array($experiment->status, [GtmExperimentStatus::Assigned, GtmExperimentStatus::Approved, GtmExperimentStatus::Running], true)) {
            $this->block($blockers, 'experiment_changed', 'The exact experiment is no longer eligible.');
        }

        $validUntil = $this->approvalExpiry($policyVersion, $version, $actions);
        $actionFacts = [];
        foreach ($actions as $action) {
            $actionFacts[(string) $action->id] = $this->validateAction(
                $plan,
                $action,
                $run,
                $version,
                $snapshot,
                $experiment,
                $policyVersion,
                $blockers,
                $validUntil,
                $lock,
            );
        }

        if ($validUntil === null || ! now()->lessThan($validUntil)) {
            $this->block($blockers, 'approval_window_closed', 'No safe approval lifetime remains before the scheduled window closes.');
        }
        $facts += [
            'readiness_hash' => $readinessHash,
            'run_id' => $run?->id,
            'run_input_hash' => $run?->input_hash,
            'objective_id' => $objective?->id,
            'objective_version_id' => $version?->id,
            'objective_content_hash' => $version?->content_hash,
            'objective_current_plan_id' => $objective?->current_plan_id,
            'audience_snapshot_id' => $snapshot?->id,
            'audience_evaluation_hash' => $snapshot?->evaluation_hash,
            'experiment_id' => $experiment?->id,
            'assignment_seed_hash' => $experiment?->assignment_seed_hash,
            'policy_version_id' => $policyVersion?->id,
            'policy_content_hash' => $policyVersion?->content_hash,
            'actions' => collect($actionFacts)->sortKeys()->all(),
        ];
        $dependencyHash = CanonicalObjectivePayload::hash($facts);
        $actionInputHashes = [];
        foreach ($actions as $action) {
            $actionInputHashes[(string) $action->id] = CanonicalObjectivePayload::hash([
                'version' => 'exact_policy_input_v1',
                'workspace_id' => (int) $plan->workspace_id,
                'plan_hash' => (string) $plan->plan_hash,
                'action_id' => (string) $action->id,
                'action_fingerprint' => (string) $action->action_fingerprint,
                'policy_version_id' => $policyVersion instanceof OutreachPolicyVersion ? (string) $policyVersion->id : '',
                'policy_content_hash' => $policyVersion instanceof OutreachPolicyVersion ? (string) $policyVersion->content_hash : '',
                'dependency_hash' => $dependencyHash,
            ]);
        }

        return new ExactPlanValidation(
            blockers: $blockers,
            warnings: $warnings,
            actionInputHashes: $actionInputHashes,
            dependencyHash: $dependencyHash,
            policyVersionId: $policyVersion instanceof OutreachPolicyVersion ? (string) $policyVersion->id : '',
            validUntil: $validUntil,
            actions: $actions,
        );
    }

    /**
     * @param  list<array{code:string,message:string,action_id?:string}>  $blockers
     * @return array<string,mixed>
     */
    private function validateAction(
        ExecutionPlan $plan,
        ExecutionPlanAction $action,
        ?ObjectiveRun $run,
        ?ObjectiveVersion $version,
        ?AudienceSnapshot $snapshot,
        ?GtmExperiment $experiment,
        ?OutreachPolicyVersion $policyVersion,
        array &$blockers,
        ?CarbonInterface &$validUntil,
        bool $lock,
    ): array {
        $actionId = (string) $action->id;
        $facts = [
            'action_id' => $actionId,
            'action_fingerprint' => (string) $action->action_fingerprint,
            'stored_policy_input_hash' => (string) $action->policy_input_hash,
        ];
        if ($action->body_html !== null || (int) $action->position !== 1) {
            $this->block($blockers, 'action_unsupported', 'Only one exact plain-text new-thread action is supported.', $actionId);
        }
        if (! $version instanceof ObjectiveVersion
            || ! $action->scheduled_not_before->lessThan($action->scheduled_not_after)
            || ! $action->scheduled_not_after->equalTo($version->ends_at)
            || ! now()->lessThan($action->scheduled_not_after)) {
            $this->block($blockers, 'schedule_changed', 'The exact scheduled window changed or closed.', $actionId);
        }

        $arm = $this->lock(GtmExperimentArm::query()
            ->where('workspace_id', $plan->workspace_id)
            ->where('gtm_experiment_id', $plan->gtm_experiment_id)
            ->whereKey($action->gtm_experiment_arm_id), $lock)->first();
        $assignment = $this->lock(GtmExperimentAssignment::query()
            ->where('workspace_id', $plan->workspace_id)
            ->where('gtm_experiment_id', $plan->gtm_experiment_id)
            ->where('audience_snapshot_id', $plan->audience_snapshot_id)
            ->whereKey($action->gtm_experiment_assignment_id), $lock)->first();
        $member = $this->lock(AudienceSnapshotMember::query()
            ->where('workspace_id', $plan->workspace_id)
            ->where('audience_snapshot_id', $plan->audience_snapshot_id)
            ->whereKey($action->audience_snapshot_member_id), $lock)->first();
        $memberLink = $assignment instanceof GtmExperimentAssignment
            ? $this->lock(GtmExperimentAssignmentMember::query()
                ->where('workspace_id', $plan->workspace_id)
                ->where('gtm_experiment_assignment_id', $assignment->id)
                ->where('audience_snapshot_member_id', $action->audience_snapshot_member_id), $lock)->first()
            : null;
        if (! $arm instanceof GtmExperimentArm
            || ! $assignment instanceof GtmExperimentAssignment
            || ! $member instanceof AudienceSnapshotMember
            || ! $memberLink instanceof GtmExperimentAssignmentMember
            || ! hash_equals((string) $assignment->gtm_experiment_arm_id, (string) $arm->id)
            || (int) $assignment->account_id !== (int) $action->account_id
            || $member->result !== AudienceMemberResult::Included
            || (int) $member->account_id !== (int) $action->account_id
            || (int) $member->person_id !== (int) $action->person_id
            || ! hash_equals((string) $memberLink->member_hash, (string) $member->member_hash)) {
            $this->block($blockers, 'assignment_changed', 'The exact audience assignment no longer matches this action.', $actionId);
        }

        $person = $this->lock(Person::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($action->person_id), $lock)->first();
        $account = $this->lock(Account::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($action->account_id), $lock)->first();
        $email = $person instanceof Person ? EmailNormalizer::normalize((string) $person->email_address) : '';
        $recipientSnapshot = [];
        try {
            $recipientSnapshot = $person instanceof Person && $account instanceof Account
                ? $this->templates->recipientSnapshot($person->first_name, $person->full_name, (string) $account->name)
                : [];
        } catch (Throwable) {
            // Report the same stable recipient blocker below.
        }
        $recipientHash = $person instanceof Person && $account instanceof Account && $recipientSnapshot !== []
            ? CanonicalObjectivePayload::hash([
                'person_id' => (int) $person->id,
                'account_id' => (int) $account->id,
                'member_hash' => $member instanceof AudienceSnapshotMember ? (string) $member->member_hash : '',
                'destination_email_hash' => hash('sha256', $email),
                ...$recipientSnapshot,
            ])
            : '';
        if (! $person instanceof Person || ! $account instanceof Account
            || (int) $person->account_id !== (int) $account->id
            || ! EmailNormalizer::isValid($email)
            || ! hash_equals((string) ($action->destination_email_address ?? ''), $email)
            || ! hash_equals((string) $action->destination_email_hash, hash('sha256', $email))
            || ! hash_equals((string) $action->recipient_snapshot_hash, $recipientHash)) {
            $this->block($blockers, 'recipient_changed', 'The exact recipient identity or personalization snapshot changed.', $actionId);
        }
        if ($email !== '' && SuppressionEntry::query()
            ->where('workspace_id', $plan->workspace_id)
            ->where('email_normalized', $email)->exists()) {
            $this->block($blockers, 'recipient_suppressed', 'The recipient is currently suppressed.', $actionId);
        }

        $mailbox = $this->lock(Mailbox::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($action->mailbox_id), $lock)->first();
        $connection = $this->lock(ProviderConnection::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($action->provider_connection_id), $lock)->first();
        $sender = null;
        if ($mailbox instanceof Mailbox) {
            try {
                $sender = $this->senderIdentities->effective($mailbox);
            } catch (SenderIdentityUnavailable) {
                $sender = null;
            }
        }
        if (! $mailbox instanceof Mailbox || ! $connection instanceof ProviderConnection
            || (int) $mailbox->provider_connection_id !== (int) $connection->id
            || $this->value($mailbox->status) !== 'connected'
            || $mailbox->sending_paused_at !== null
            || $this->value($connection->status) !== 'connected'
            || (int) $connection->authorization_version !== (int) $action->provider_connection_version) {
            $this->block($blockers, 'mailbox_unhealthy', 'The exact mailbox or connection is not ready.', $actionId);
        }
        if (! is_array($sender)
            || ! hash_equals((string) $action->sender_address_snapshot, $sender['email'])
            || ! $this->nullableEquals($action->sender_display_name_snapshot, $sender['name'] ?? null)
            || ! hash_equals((string) ($action->reply_to_snapshot ?? ''), $sender['email'])) {
            $this->block($blockers, 'sender_identity_changed', 'The exact sender identity changed.', $actionId);
        }

        $strategy = $this->lock(StrategyVersion::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($action->strategy_version_id), $lock)->first();
        $strategyAggregate = $strategy instanceof StrategyVersion
            ? $this->lock(Strategy::query()->where('workspace_id', $plan->workspace_id)
                ->whereKey($strategy->strategy_id), $lock)->first()
            : null;
        $draft = $this->lock(ObjectiveRunArmDraft::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($action->objective_run_arm_draft_id), $lock)->first();
        if (! $strategy instanceof StrategyVersion
            || ! $strategyAggregate instanceof Strategy
            || $strategyAggregate->status !== StrategyStatus::Active
            || ! hash_equals((string) ($strategyAggregate->current_version_id ?? ''), (string) $strategy->id)
            || ! $draft instanceof ObjectiveRunArmDraft
            || $draft->getAttribute('origin') !== $plan->getAttribute('origin')
            || $draft->state !== ObjectiveRunArmDraftState::Succeeded
            || ! $arm instanceof GtmExperimentArm
            || ! hash_equals((string) $arm->strategy_version_id, (string) $action->strategy_version_id)) {
            $this->block($blockers, 'strategy_changed', 'The exact Strategy or successful arm draft changed.', $actionId);
        }

        $reason = $member instanceof AudienceSnapshotMember
            ? $this->currentReason($member, (int) $plan->workspace_id, $lock)
            : null;
        $claimIds = array_map('strval', $action->selected_claim_ids);
        if (! $reason instanceof ReasonToContactVersion || $claimIds === []) {
            $this->block($blockers, 'reason_to_contact_changed', 'The exact safe Reason to Contact changed.', $actionId);
        } elseif (ReasonToContactClaim::query()
            ->where('workspace_id', $plan->workspace_id)
            ->where('reason_to_contact_version_id', $reason->id)
            ->whereIn('claim_id', $claimIds)->count() !== count(array_unique($claimIds))) {
            $this->block($blockers, 'reason_to_contact_changed', 'A selected Claim no longer belongs to the exact Reason to Contact.', $actionId);
        }
        if ($reason instanceof ReasonToContactVersion) {
            $this->tightenExpiry($validUntil, $reason->fresh_until);
            $this->tightenExpiry($validUntil, $reason->expires_at);
        }
        if ($member instanceof AudienceSnapshotMember) {
            $this->tightenExpiry($validUntil, $member->evidence_fresh_until);
        }

        $verified = null;
        if ($member instanceof AudienceSnapshotMember && $account instanceof Account && count($claimIds) === 1) {
            $frozen = collect($member->evidence_manifest)->first(
                fn (array $item): bool => hash_equals((string) ($item['claim_id'] ?? ''), $claimIds[0]),
            );
            $verified = is_array($frozen)
                ? $this->evidence->verifyFrozen($frozen, (int) $plan->workspace_id, (int) $account->id)
                : null;
        }
        $evidenceIds = is_array($verified) && is_array($verified['supporting_evidence'] ?? null)
            ? collect($verified['supporting_evidence'])
                ->map(fn (mixed $edge): string => is_array($edge) ? (string) ($edge['evidence_id'] ?? '') : '')
                ->filter()->unique()->sort()->values()->all()
            : [];
        if (! is_array($verified)
            || $evidenceIds !== array_map('strval', $action->selected_evidence_ids)
            || $action->selected_claim_safety_hashes !== [$claimIds[0] ?? '' => (string) ($verified['safety_hash'] ?? '')]) {
            $this->block($blockers, 'evidence_changed', 'The exact current Claim evidence no longer matches the action.', $actionId);
        }

        $offer = $version instanceof ObjectiveVersion
            ? $this->lock(Offer::query()->where('workspace_id', $plan->workspace_id)
                ->whereKey($version->offer_id), $lock)->first()
            : null;
        if (! $offer instanceof Offer
            || $offer->status !== OfferStatus::Active
            || ! hash_equals((string) ($offer->current_version_id ?? ''), (string) $action->offer_version_id)
            || ! hash_equals((string) $plan->offer_version_id, (string) $action->offer_version_id)) {
            $this->block($blockers, 'offer_changed', 'The exact current active Offer changed.', $actionId);
        }
        $conversion = $version instanceof ObjectiveVersion
            ? $this->lock(ConversionDefinition::query()->where('workspace_id', $plan->workspace_id)
                ->whereKey($version->conversion_definition_id), $lock)->first()
            : null;
        if (! $conversion instanceof ConversionDefinition
            || $conversion->status !== ConversionDefinitionStatus::Active
            || ! hash_equals((string) ($conversion->current_version_id ?? ''), (string) $action->conversion_definition_version_id)
            || ! hash_equals((string) $plan->conversion_definition_version_id, (string) $action->conversion_definition_version_id)) {
            $this->block($blockers, 'conversion_changed', 'The exact Conversion Definition changed.', $actionId);
        }

        $strategyContract = null;
        $offerContract = null;
        if ($arm instanceof GtmExperimentArm && $strategy instanceof StrategyVersion) {
            try {
                $contract = $this->prompts->handle($arm)['contract'];
                $strategyContract = $contract['strategy'] ?? null;
                $offerContract = $contract['offer'] ?? null;
                if (! is_array($strategyContract)
                    || ! hash_equals(
                        CanonicalObjectivePayload::hash($strategyContract),
                        CanonicalObjectivePayload::hash($this->templates->strategyContract($strategy)),
                    )) {
                    $strategyContract = null;
                }
            } catch (Throwable) {
                $strategyContract = null;
                $offerContract = null;
            }
        }
        if (! is_array($strategyContract) || ! is_array($offerContract)
            || ! hash_equals((string) $action->offer_content_hash, (string) ($offerContract['content_hash'] ?? ''))
            || ! hash_equals((string) $action->offer_contract_hash, (string) ($offerContract['contract_hash'] ?? ''))
            || ! hash_equals((string) $action->cta_destination_url, (string) ($offerContract['destination_url'] ?? ''))
            || ! hash_equals((string) $action->cta_response_route, (string) ($offerContract['response_route'] ?? ''))) {
            $this->block($blockers, 'offer_content_changed', 'The exact Offer and CTA contract changed.', $actionId);
        }

        if (is_array($verified) && is_array($strategyContract) && is_array($offerContract) && $recipientSnapshot !== []) {
            try {
                $messageTemplates = $this->templates->render($verified, $offerContract, $strategyContract);
                $rendered = $this->renderer->previewTemplates(
                    $messageTemplates['subject'],
                    $messageTemplates['body'],
                    $recipientSnapshot,
                );
                $claimManifest = $draft?->claim_manifest;
                $templateHash = CanonicalObjectivePayload::hash([
                    'template_version' => DeterministicMessageTemplate::VERSION,
                    'strategy_projection' => $strategyContract['projection'],
                    'strategy_projection_hash' => $strategyContract['projection_hash'],
                    'message_pattern' => is_array($claimManifest) ? $claimManifest['message_pattern'] ?? null : null,
                    'evidence_choice' => is_array($claimManifest) ? $claimManifest['evidence_choice'] ?? null : null,
                    'claim_id' => $claimIds[0],
                    'claim_safety_hash' => $verified['safety_hash'],
                    'offer_contract_hash' => $offerContract['contract_hash'],
                    'subject_template' => $messageTemplates['subject'],
                    'body_template' => $messageTemplates['body'],
                ]);
                $contentHash = CanonicalObjectivePayload::hash([
                    'subject' => $rendered['subject'],
                    'body_text' => $rendered['body'],
                    'body_html' => null,
                ]);
                if (! hash_equals((string) $action->template_hash, $templateHash)
                    || ! hash_equals((string) ($action->subject ?? ''), (string) $rendered['subject'])
                    || ! hash_equals((string) ($action->body_text ?? ''), (string) $rendered['body'])
                    || ! hash_equals((string) $action->content_hash, $contentHash)) {
                    $this->block($blockers, 'content_changed', 'The exact subject or body bytes changed.', $actionId);
                }
            } catch (Throwable) {
                $this->block($blockers, 'content_changed', 'The exact message can no longer be reproduced safely.', $actionId);
            }
        }

        $strategyProjection = is_array($strategyContract) ? $strategyContract['projection'] ?? null : null;
        $currentPolicyInputHash = is_array($strategyProjection) && $assignment instanceof GtmExperimentAssignment
            && $member instanceof AudienceSnapshotMember && $mailbox instanceof Mailbox && $connection instanceof ProviderConnection
            ? CanonicalObjectivePayload::hash([
                'policy_version_id' => $policyVersion instanceof OutreachPolicyVersion ? (string) $policyVersion->id : '',
                'objective_version_id' => $run instanceof ObjectiveRun ? (string) $run->objective_version_id : '',
                'assignment_id' => (string) $assignment->id,
                'member_id' => (string) $member->id,
                'mailbox_id' => (int) $mailbox->id,
                'provider_connection_id' => (int) $connection->id,
                'provider_connection_version' => (int) $connection->authorization_version,
                'recipient_snapshot_hash' => $recipientHash,
                'strategy_projection' => $strategyProjection,
                'strategy_projection_hash' => (string) ($strategyContract['projection_hash'] ?? ''),
            ])
            : '';
        if (! hash_equals((string) $action->policy_input_hash, $currentPolicyInputHash)) {
            $this->block($blockers, 'policy_input_changed', 'The exact policy input changed.', $actionId);
        }

        if (ExecutionActionEvent::query()
            ->where('workspace_id', $plan->workspace_id)
            ->where('execution_plan_action_id', $action->id)
            ->whereIn('event', ['attempted', 'provider_accepted', 'uncertain', 'confirmed_sent'])
            ->exists()) {
            $this->block($blockers, 'provider_attempt_exists', 'This action already reached or may have reached the provider.', $actionId);
        }

        return $facts + [
            'member_hash' => $member?->member_hash,
            'assignment_id' => $assignment?->id,
            'strategy_version_id' => $strategy?->id,
            'strategy_content_hash' => $strategy?->content_hash,
            'reason_to_contact_version_id' => $reason?->id,
            'reason_content_hash' => $reason?->content_hash,
            'evidence_safety_hash' => $verified['safety_hash'] ?? null,
            'offer_current_version_id' => $offer?->current_version_id,
            'conversion_current_version_id' => $conversion?->current_version_id,
            'recipient_snapshot_hash' => $recipientHash,
            'mailbox_id' => $mailbox?->id,
            'mailbox_status' => $mailbox === null ? null : $this->value($mailbox->status),
            'sender_email' => $sender['email'] ?? null,
            'provider_connection_id' => $connection?->id,
            'provider_connection_version' => $connection?->authorization_version,
            'current_policy_input_hash' => $currentPolicyInputHash,
            'content_hash' => (string) $action->content_hash,
            'scheduled_not_before' => $action->scheduled_not_before->toAtomString(),
            'scheduled_not_after' => $action->scheduled_not_after->toAtomString(),
        ];
    }

    private function currentReason(AudienceSnapshotMember $member, int $workspaceId, bool $lock): ?ReasonToContactVersion
    {
        $query = ReasonToContactVersion::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($member->reason_to_contact_version_id)
            ->where('account_id', $member->account_id)
            ->where('qualification_decision_id', $member->qualification_decision_id)
            ->where('content_hash', $member->reason_content_hash)
            ->whereNull('successor_id')
            ->where('safe_for_message', true)
            ->where('sensitive_inference', false)
            ->where('prohibited_inference', false)
            ->where('fresh_until', '>', now())
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));

        return $this->lock($query, $lock)->first();
    }

    /** @param Collection<int, ExecutionPlanAction> $actions */
    private function approvalExpiry(
        ?OutreachPolicyVersion $policy,
        ?ObjectiveVersion $version,
        Collection $actions,
    ): ?CarbonInterface {
        if (! $policy instanceof OutreachPolicyVersion) {
            return null;
        }
        $hours = min(
            max(0, (int) $policy->approval_ttl_hours),
            max(0, (int) config('objectives.safety.approval_ttl_hours')),
        );
        if ($hours < 1) {
            return null;
        }
        $expires = now()->addHours($hours);
        $this->tightenExpiry($expires, $version?->ends_at);
        foreach ($actions as $action) {
            $this->tightenExpiry($expires, $action->scheduled_not_after);
        }

        return $expires;
    }

    private function tightenExpiry(?CarbonInterface &$expires, mixed $boundary): void
    {
        if ($expires === null || $boundary === null) {
            return;
        }
        $candidate = Carbon::parse($boundary)->subSecond();
        if ($candidate->lessThan($expires)) {
            $expires = $candidate;
        }
    }

    /** @return Builder<ExecutionPlan> */
    private function planQuery(ExecutionPlan $plan, bool $lock): Builder
    {
        return $this->lock(ExecutionPlan::query()
            ->where('workspace_id', $plan->workspace_id)
            ->whereKey($plan->id), $lock);
    }

    /** @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function lock(Builder $query, bool $lock): Builder
    {
        return $lock ? $query->lockForUpdate() : $query;
    }

    /** @param list<array{code:string,message:string,action_id?:string}> $items */
    private function block(array &$items, string $code, string $message, ?string $actionId = null): void
    {
        if (collect($items)->contains(fn (array $item): bool => $item['code'] === $code
            && ($item['action_id'] ?? null) === $actionId)) {
            return;
        }
        $item = compact('code', 'message');
        if (is_string($actionId) && $actionId !== '') {
            $item['action_id'] = $actionId;
        }
        $items[] = $item;
    }

    /** @param list<array{code:string,message:string,action_id?:string}> $items */
    private function warn(array &$items, string $code, string $message): void
    {
        if (! collect($items)->contains('code', $code)) {
            $items[] = compact('code', 'message');
        }
    }

    private function value(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    private function nullableEquals(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return hash_equals((string) $left, (string) $right);
    }
}
