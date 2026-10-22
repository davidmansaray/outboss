<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\Objectives\Enums\ExecutionActionEventType;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ExecutionPlanActionKind;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Domain\Objectives\Enums\ExecutionPlanStatus;
use App\Domain\Objectives\Enums\ObjectiveRunArmDraftState;
use App\Domain\Objectives\Enums\ObjectiveRunDispatchState;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseName;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseState;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Domain\Objectives\Exceptions\DraftMessageValidationException;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\DeterministicMessageTemplate;
use App\Domain\Objectives\Support\ExecutionPlanFingerprint;
use App\Domain\Objectives\Support\MessageEvidenceContract;
use App\Models\Account;
use App\Models\AudienceSnapshotMember;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\GtmExperimentArm;
use App\Models\GtmExperimentAssignment;
use App\Models\GtmExperimentAssignmentMember;
use App\Models\Mailbox;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use App\Models\ObjectiveRunDispatch;
use App\Models\ObjectiveRunPhase;
use App\Models\ObjectiveVersion;
use App\Models\OutreachPolicy;
use App\Models\Person;
use App\Models\ReasonToContactClaim;
use App\Models\ReasonToContactVersion;
use App\Models\StrategyVersion;
use App\Models\SuppressionEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ValidateExecutionPlan
{
    public const VERSION = 'execution_plan_validator_v6';

    public function __construct(
        private readonly RenderSequenceMessage $renderer,
        private readonly MailboxSenderIdentityService $senderIdentities,
        private readonly ObjectiveRunPreflight $preflight,
        private readonly MessageEvidenceContract $messageEvidence,
        private readonly DeterministicMessageTemplate $templates,
        private readonly BuildObjectiveDraftPrompt $prompts,
        private readonly RecordExecutionActionEvent $actionEvents,
    ) {}

    public function handle(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        int $expectedFence,
    ): ?ExecutionPlan {
        return DB::transaction(function () use ($run, $phase, $dispatch, $expectedFence): ?ExecutionPlan {
            $run = $this->lockOwnedPlanContext($run, $phase, $dispatch, $expectedFence);
            if ($run === null) {
                return null;
            }
            if ($run->getAttribute('origin') !== ExecutionOrigin::Ai) {
                throw new InvalidArgumentException('Human execution must use the human campaign compiler.');
            }
            $objective = $run->objective()->where('workspace_id', $run->workspace_id)->lockForUpdate()->firstOrFail();
            $existing = $run->executionPlans()->orderByDesc('version')->first();
            if ($existing !== null) {
                if ($objective->current_plan_id === null) {
                    $objective->forceFill(['current_plan_id' => $existing->id])->save();
                } elseif (! hash_equals((string) $objective->current_plan_id, (string) $existing->id)) {
                    throw ValidationException::withMessages([
                        'plan' => 'The Objective current plan pointer conflicts with this run.',
                    ]);
                }

                return $existing->load('actions');
            }
            $preflight = $this->preflight->handle($objective, $run->experiment);
            if (! $preflight->ready() || ! hash_equals((string) $run->input_hash, $preflight->inputHash)) {
                throw ValidationException::withMessages([
                    'run' => 'The Objective run inputs changed before plan validation.',
                ]);
            }
            $objectiveVersion = $run->objectiveVersion()->where('workspace_id', $run->workspace_id)
                ->lockForUpdate()->firstOrFail();
            $run->setRelation('objectiveVersion', $objectiveVersion);
            $policy = OutreachPolicy::query()->where('workspace_id', $run->workspace_id)
                ->where('status', 'active')->with('currentVersion')->firstOrFail();
            $policyVersion = $policy->currentVersion;
            if ($policyVersion === null || $policy->execution_paused_at !== null) {
                throw ValidationException::withMessages(['policy' => 'The workspace outreach policy is unavailable.']);
            }
            $mailbox = Mailbox::query()->where('workspace_id', $run->workspace_id)
                ->where('status', 'connected')->whereNull('sending_paused_at')
                ->whereNotNull('selected_sender_identity_key')
                ->whereHas('connection', fn ($query) => $query->where('status', 'connected'))
                ->with('connection')->orderBy('id')->first();
            if ($mailbox === null || $mailbox->connection === null) {
                throw ValidationException::withMessages(['mailbox' => 'A healthy mailbox and connection are required to build exact actions.']);
            }
            $sender = $this->senderIdentities->effective($mailbox);
            $planId = (string) Str::uuid();
            $candidateActions = [];
            $strategyContracts = [];
            $omitted = [];
            $drafts = ObjectiveRunArmDraft::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('objective_run_id', $run->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('gtm_experiment_arm_id');
            foreach ($run->experiment->assignments()->with(['members', 'arm'])->orderBy('id')->get() as $assignment) {
                $draft = $drafts->get($assignment->gtm_experiment_arm_id);
                if (! $draft instanceof ObjectiveRunArmDraft || $draft->state !== ObjectiveRunArmDraftState::Succeeded) {
                    foreach ($assignment->members as $memberLink) {
                        $omitted[] = ['member_id' => (string) $memberLink->audience_snapshot_member_id, 'reason' => 'arm_draft_unavailable'];
                    }

                    continue;
                }
                $lockedArm = GtmExperimentArm::query()
                    ->where('workspace_id', $run->workspace_id)
                    ->where('gtm_experiment_id', $run->gtm_experiment_id)
                    ->whereKey($assignment->gtm_experiment_arm_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $assignment->setRelation('arm', $lockedArm);
                $strategy = StrategyVersion::query()->where('workspace_id', $run->workspace_id)
                    ->whereKey($lockedArm->strategy_version_id)->lockForUpdate()->firstOrFail();
                $armContract = $this->prompts->handle($lockedArm)['contract'];
                $strategyContract = $this->templates->strategyContract($strategy);
                if (! is_array($armContract['strategy'] ?? null)
                    || ! hash_equals(
                        CanonicalObjectivePayload::hash($strategyContract),
                        CanonicalObjectivePayload::hash($armContract['strategy']),
                    )) {
                    throw ValidationException::withMessages([
                        'strategy' => 'The outbound Strategy changed while the exact plan was being built.',
                    ]);
                }
                if (! $this->draftStrategyContractMatches($draft, $strategyContract)) {
                    throw ValidationException::withMessages([
                        'strategy' => 'The drafted arm no longer matches its frozen outbound Strategy.',
                    ]);
                }
                $strategyContracts[(string) $strategy->id] = $strategyContract;
                foreach ($assignment->members->sortBy('audience_snapshot_member_id') as $memberLink) {
                    $candidate = $this->candidateAction(
                        $run,
                        $assignment,
                        $memberLink,
                        $draft,
                        $strategy,
                        $mailbox,
                        $sender,
                        $policyVersion->id,
                        $armContract,
                    );
                    if (is_string($candidate)) {
                        $omitted[] = ['member_id' => (string) $memberLink->audience_snapshot_member_id, 'reason' => $candidate];

                        continue;
                    }
                    $candidate = [
                        'id' => (string) Str::uuid(),
                        'workspace_id' => (int) $run->workspace_id,
                        'execution_plan_id' => $planId,
                        ...$candidate,
                    ];
                    $candidate['action_fingerprint'] = ExecutionPlanFingerprint::action($candidate);
                    $candidate['state'] = ExecutionPlanActionState::AwaitingApproval;
                    $candidateActions[] = $candidate;
                }
            }
            if (count($candidateActions) > (int) $objectiveVersion->maximum_messages
                || count($candidateActions) > (int) $policyVersion->maximum_messages) {
                throw ValidationException::withMessages(['actions' => 'The exact action set exceeds the approved message cap.']);
            }
            $actionFingerprints = array_column($candidateActions, 'action_fingerprint');
            sort($actionFingerprints, SORT_STRING);
            $manifest = [
                'workspace_id' => (int) $run->workspace_id,
                'run_id' => (string) $run->id,
                'run_input_hash' => (string) $run->input_hash,
                'objective_version_id' => (string) $run->objective_version_id,
                'audience_snapshot_id' => (string) $run->audience_snapshot_id,
                'gtm_experiment_id' => (string) $run->gtm_experiment_id,
                'assignment_seed_hash' => (string) $run->experiment->assignment_seed_hash,
                'outreach_policy_version_id' => (string) $policyVersion->id,
                'strategy_contracts' => collect($strategyContracts)->sortKeys()->all(),
                'action_fingerprints' => $actionFingerprints,
                'omitted' => $omitted,
            ];
            $canonicalHash = CanonicalObjectivePayload::hash($manifest);
            $planHash = ExecutionPlanFingerprint::plan($planId, $canonicalHash, $candidateActions);
            $firstDraft = $run->armDrafts->first();
            $status = $omitted === [] && $candidateActions !== []
                ? ExecutionPlanStatus::AwaitingApproval
                : ExecutionPlanStatus::Partial;
            $modelId = $firstDraft instanceof ObjectiveRunArmDraft ? $firstDraft->model_id : '';
            $this->assertActionIntegrity($run, $objectiveVersion, $candidateActions, $strategyContracts);
            $plan = ExecutionPlan::query()->create([
                'id' => $planId,
                'workspace_id' => $run->workspace_id,
                'objective_run_id' => $run->id,
                'version' => 1,
                'status' => $status,
                'objective_version_id' => $run->objective_version_id,
                'audience_snapshot_id' => $run->audience_snapshot_id,
                'gtm_experiment_id' => $run->gtm_experiment_id,
                'conversion_definition_version_id' => $objectiveVersion->conversion_definition_version_id,
                'offer_version_id' => $objectiveVersion->offer_version_id,
                'outreach_policy_version_id' => $policyVersion->id,
                'model_id' => $modelId,
                'ai_task_version' => 'message_drafting_v4',
                'prompt_version' => BuildObjectiveDraftPrompt::VERSION,
                'schema_version' => (string) config('ai.tasks.message_drafting.response_schema.version'),
                'validator_version' => self::VERSION,
                'arm_count' => $run->experiment->arms->count(),
                'account_count' => collect($candidateActions)->pluck('account_id')->unique()->count(),
                'people_count' => collect($candidateActions)->pluck('person_id')->unique()->count(),
                'action_count' => count($candidateActions),
                'maximum_cost_micros' => (int) $run->maximum_cost_micros,
                'estimated_cost_micros' => $preflight->estimatedCostMicros,
                'actual_cost_micros' => (int) $run->actual_cost_micros,
                'input_manifest' => $manifest,
                'evidence_manifest_hash' => CanonicalObjectivePayload::hash(
                    collect($candidateActions)->map(fn (array $action): array => [
                        'action_id' => (string) $action['id'],
                        'claim_ids' => $action['selected_claim_ids'],
                        'evidence_ids' => $action['selected_evidence_ids'],
                        'claim_safety_hashes' => $action['selected_claim_safety_hashes'],
                        'offer_contract_hash' => $action['offer_contract_hash'],
                    ])->sortBy('action_id')->values()->all(),
                ),
                'compilation_manifest' => ['version' => 'execution_plan_compilation_v1', 'campaigns' => []],
                'canonical_payload_hash' => $canonicalHash,
                'plan_hash' => $planHash,
                'created_by_user_id' => $run->created_by_user_id,
            ]);
            foreach ($candidateActions as $candidate) {
                $action = ExecutionPlanAction::query()->create($candidate);
                $this->actionEvents->handle($action, ExecutionActionEventType::Planned);
            }
            $objective->forceFill(['current_plan_id' => $plan->id])->save();

            return $plan->fresh('actions');
        }, 3);
    }

    /**
     * Lock and revalidate the exact claimed run, phase and consumed dispatch.
     *
     * Recovery deliberately reuses a dispatch row, so its ID alone cannot
     * establish ownership. The phase attempt, live leases and monotonically
     * increasing fence must all still match before a plan, action or Objective
     * current-plan pointer can be read for reuse or mutated.
     */
    private function lockOwnedPlanContext(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        int $expectedFence,
    ): ?ObjectiveRun {
        $leaseOwner = $run->lease_owner;
        $runInputHash = (string) $run->input_hash;
        $phaseInputHash = (string) $phase->input_hash;
        if (! is_string($leaseOwner) || $leaseOwner === ''
            || $expectedFence < 1
            || (int) $run->fencing_token !== $expectedFence
            || $runInputHash === ''
            || (int) $phase->workspace_id !== (int) $run->workspace_id
            || (string) $phase->objective_run_id !== (string) $run->id
            || $phase->phase !== ObjectiveRunPhaseName::ValidatePlan
            || $phase->state !== ObjectiveRunPhaseState::Running
            || (int) $phase->fencing_token !== $expectedFence
            || ! hash_equals((string) $phase->lease_owner, $leaseOwner)
            || $phaseInputHash === ''
            || (int) $dispatch->workspace_id !== (int) $run->workspace_id
            || (string) $dispatch->objective_run_id !== (string) $run->id
            || $dispatch->phase !== ObjectiveRunPhaseName::ValidatePlan
            || $dispatch->state !== ObjectiveRunDispatchState::Consumed
            || $dispatch->consumed_at === null
            || ! hash_equals((string) $dispatch->input_hash, $phaseInputHash)) {
            return null;
        }

        $now = now();
        $lockedRun = ObjectiveRun::query()
            ->where('workspace_id', $run->workspace_id)
            ->whereKey($run->id)
            ->where('state', ObjectiveRunState::Running->value)
            ->where('current_phase', ObjectiveRunPhaseName::ValidatePlan->value)
            ->where('input_hash', $runInputHash)
            ->where('fencing_token', $expectedFence)
            ->where('lease_owner', $leaseOwner)
            ->where('lease_expires_at', '>', $now)
            ->lockForUpdate()
            ->with(['objectiveVersion', 'experiment.arms', 'armDrafts'])
            ->first();
        if ($lockedRun === null) {
            return null;
        }
        $lockedPhase = ObjectiveRunPhase::query()
            ->where('workspace_id', $run->workspace_id)
            ->where('objective_run_id', $run->id)
            ->whereKey($phase->id)
            ->where('phase', ObjectiveRunPhaseName::ValidatePlan->value)
            ->where('state', ObjectiveRunPhaseState::Running->value)
            ->where('input_hash', $phaseInputHash)
            ->where('fencing_token', $expectedFence)
            ->where('lease_owner', $leaseOwner)
            ->where('lease_expires_at', '>', $now)
            ->lockForUpdate()
            ->first();
        if ($lockedPhase === null) {
            return null;
        }
        $lockedDispatch = ObjectiveRunDispatch::query()
            ->where('workspace_id', $run->workspace_id)
            ->where('objective_run_id', $run->id)
            ->whereKey($dispatch->id)
            ->where('phase', ObjectiveRunPhaseName::ValidatePlan->value)
            ->where('state', ObjectiveRunDispatchState::Consumed->value)
            ->where('input_hash', $phaseInputHash)
            ->whereNotNull('consumed_at')
            ->lockForUpdate()
            ->first();

        return $lockedDispatch === null ? null : $lockedRun;
    }

    /**
     * @param  array{key:string,email:string,name:string|null}  $sender
     * @param  array<string,mixed>  $armContract
     * @return array<string,mixed>|string
     */
    private function candidateAction(
        ObjectiveRun $run,
        GtmExperimentAssignment $assignment,
        GtmExperimentAssignmentMember $memberLink,
        ObjectiveRunArmDraft $draft,
        StrategyVersion $strategy,
        Mailbox $mailbox,
        array $sender,
        string $policyVersionId,
        array $armContract,
    ): array|string {
        $member = AudienceSnapshotMember::query()->where('workspace_id', $run->workspace_id)
            ->whereKey($memberLink->audience_snapshot_member_id)->first();
        if ($member === null || $member->person_id === null || $member->account_id === null
            || (int) $member->account_id !== (int) $assignment->account_id) {
            return 'audience_identity_changed';
        }
        $person = Person::query()->where('workspace_id', $run->workspace_id)->whereKey($member->person_id)->first();
        $account = Account::query()->where('workspace_id', $run->workspace_id)->whereKey($member->account_id)->first();
        if ($person === null || $account === null || (int) $person->account_id !== (int) $account->id) {
            return 'audience_identity_changed';
        }
        $email = EmailNormalizer::normalize($person->email_address);
        if (! EmailNormalizer::isValid($email)) {
            return 'recipient_email_invalid';
        }
        if (SuppressionEntry::query()->where('workspace_id', $run->workspace_id)
            ->where('email_normalized', $email)->exists()) {
            return 'recipient_suppressed';
        }
        $draftManifest = $draft->claim_manifest;
        $ctaManifest = $draft->cta_manifest;
        $choiceId = is_array($draftManifest) ? $draftManifest['evidence_choice'] ?? null : null;
        $messagePattern = is_array($draftManifest) ? $draftManifest['message_pattern'] ?? null : null;
        $offerContract = $armContract['offer'] ?? null;
        $strategyContract = $armContract['strategy'] ?? null;
        $contractChoices = $armContract['evidence_choices'] ?? null;
        $choice = is_array($contractChoices)
            ? collect($contractChoices)->first(fn (mixed $candidate): bool => is_array($candidate)
                && hash_equals((string) ($candidate['choice_id'] ?? ''), (string) $choiceId))
            : null;
        if (! is_array($draftManifest)
            || ! is_array($ctaManifest)
            || ! is_array($offerContract)
            || ! is_array($strategyContract)
            || ! is_array($choice)
            || ($armContract['contract_version'] ?? null) !== BuildObjectiveDraftPrompt::MESSAGE_CONTRACT_VERSION
            || ($armContract['template_version'] ?? null) !== DeterministicMessageTemplate::VERSION
            || $messagePattern !== DeterministicMessageTemplate::PATTERN
            || ! is_string($choiceId)
            || ($draftManifest['template_version'] ?? null) !== DeterministicMessageTemplate::VERSION
            || ! is_array($draftManifest['strategy_projection'] ?? null)
            || ! is_string($draftManifest['strategy_projection_hash'] ?? null)
            || ! hash_equals(
                CanonicalObjectivePayload::hash([
                    'projection' => $draftManifest['strategy_projection'],
                    'projection_hash' => $draftManifest['strategy_projection_hash'],
                ]),
                CanonicalObjectivePayload::hash($strategyContract),
            )
            || ! is_array($ctaManifest['strategy_projection'] ?? null)
            || ! is_string($ctaManifest['strategy_projection_hash'] ?? null)
            || ! hash_equals(
                CanonicalObjectivePayload::hash([
                    'projection' => $ctaManifest['strategy_projection'],
                    'projection_hash' => $ctaManifest['strategy_projection_hash'],
                ]),
                CanonicalObjectivePayload::hash($strategyContract),
            )
            || ! hash_equals((string) ($draftManifest['choice_coverage_hash'] ?? ''), (string) ($choice['coverage_hash'] ?? ''))
            || ! hash_equals((string) ($draftManifest['offer_version_id'] ?? ''), (string) ($offerContract['offer_version_id'] ?? ''))
            || ! hash_equals((string) ($draftManifest['offer_content_hash'] ?? ''), (string) ($offerContract['content_hash'] ?? ''))
            || ! hash_equals((string) ($draftManifest['offer_contract_hash'] ?? ''), (string) ($offerContract['contract_hash'] ?? ''))
            || ! hash_equals((string) ($ctaManifest['offer_contract_hash'] ?? ''), (string) ($offerContract['contract_hash'] ?? ''))
            || ! hash_equals((string) ($ctaManifest['destination_url'] ?? ''), (string) ($offerContract['destination_url'] ?? ''))
            || ! hash_equals((string) ($ctaManifest['response_route'] ?? ''), (string) ($offerContract['response_route'] ?? ''))) {
            return 'arm_message_contract_changed';
        }

        try {
            $strategyProjection = $this->templates->assertStrategyContract($strategyContract);
            $currentStrategyContract = $this->templates->strategyContract($strategy);
        } catch (DraftMessageValidationException) {
            return 'arm_message_contract_changed';
        }
        if (! hash_equals(
            CanonicalObjectivePayload::hash($strategyContract),
            CanonicalObjectivePayload::hash($currentStrategyContract),
        )) {
            return 'arm_message_contract_changed';
        }

        $allowedClaimIds = array_values(array_map('strval', $strategyProjection['proof_claim_ids']));
        $verifiedChoices = [];
        foreach ($member->evidence_manifest as $frozen) {
            if (! in_array((string) ($frozen['claim_id'] ?? ''), $allowedClaimIds, true)
                || $this->messageEvidence->choiceId($frozen) !== $choiceId) {
                continue;
            }
            $verified = $this->messageEvidence->verifyFrozen(
                $frozen,
                (int) $run->workspace_id,
                (int) $account->id,
            );
            if ($verified === null || $this->messageEvidence->choiceId($verified) !== $choiceId) {
                return 'member_evidence_unsafe';
            }
            try {
                $templates = $this->templates->render(
                    $verified,
                    $offerContract,
                    $strategyContract,
                );
            } catch (DraftMessageValidationException) {
                return 'member_evidence_unsafe';
            }
            $verifiedChoices[] = ['claim' => $verified, 'templates' => $templates];
        }
        usort($verifiedChoices, static fn (array $left, array $right): int => strcmp(
            (string) $left['claim']['claim_id'],
            (string) $right['claim']['claim_id'],
        ));
        $selected = $verifiedChoices[0] ?? null;
        if ($selected === null) {
            return 'member_evidence_mismatch';
        }
        $verifiedClaim = $selected['claim'];
        $templates = $selected['templates'];
        $claimId = (string) $verifiedClaim['claim_id'];
        $claimIds = [$claimId];
        $claimSafetyHashes = [$claimId => (string) $verifiedClaim['safety_hash']];
        $supportingEvidence = $verifiedClaim['supporting_evidence'] ?? null;
        if (! is_array($supportingEvidence)) {
            return 'member_evidence_mismatch';
        }
        $evidenceIds = collect($supportingEvidence)
            ->map(fn (mixed $edge): string => is_array($edge) ? (string) ($edge['evidence_id'] ?? '') : '')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
        if (! hash_equals(
            (string) ($offerContract['offer_version_id'] ?? ''),
            (string) $run->objectiveVersion->offer_version_id,
        )) {
            return 'offer_evidence_changed';
        }
        $reason = $this->currentReasonForMember($member, (int) $run->workspace_id, true);
        if ($reason === null) {
            throw ValidationException::withMessages([
                'reason_to_contact' => 'The selected Reason to Contact changed before exact action creation.',
            ]);
        }
        $this->assertClaimsBelongToReason($reason, $claimIds, (int) $run->workspace_id);
        try {
            $snapshot = $this->templates->recipientSnapshot(
                $person->first_name,
                $person->full_name,
                (string) $account->name,
            );
        } catch (DraftMessageValidationException) {
            return 'recipient_snapshot_unsafe';
        }
        try {
            $rendered = $this->renderer->previewTemplates(
                $templates['subject'],
                $templates['body'],
                $snapshot,
            );
            $this->templates->assertFinalMessage(
                $rendered,
                (string) $offerContract['destination_url'],
                (int) $strategyProjection['maximum_words'],
            );
        } catch (DraftMessageValidationException|InvalidArgumentException) {
            return 'rendered_message_unsafe';
        }
        $templateHash = CanonicalObjectivePayload::hash([
            'template_version' => DeterministicMessageTemplate::VERSION,
            'strategy_projection' => $strategyProjection,
            'strategy_projection_hash' => $strategyContract['projection_hash'],
            'message_pattern' => $messagePattern,
            'evidence_choice' => $choiceId,
            'claim_id' => $claimId,
            'claim_safety_hash' => $verifiedClaim['safety_hash'],
            'offer_contract_hash' => $offerContract['contract_hash'],
            'subject_template' => $templates['subject'],
            'body_template' => $templates['body'],
        ]);
        $recipientSnapshotHash = CanonicalObjectivePayload::hash([
            'person_id' => (int) $person->id,
            'account_id' => (int) $account->id,
            'member_hash' => (string) $member->member_hash,
            'destination_email_hash' => hash('sha256', $email),
            ...$snapshot,
        ]);
        $contentHash = CanonicalObjectivePayload::hash([
            'subject' => $rendered['subject'],
            'body_text' => $rendered['body'],
            'body_html' => null,
        ]);
        $notBefore = $run->created_at->greaterThan($run->objectiveVersion->starts_at)
            ? $run->created_at
            : $run->objectiveVersion->starts_at;
        $policyInputHash = CanonicalObjectivePayload::hash([
            'policy_version_id' => $policyVersionId,
            'objective_version_id' => (string) $run->objective_version_id,
            'assignment_id' => (string) $assignment->id,
            'member_id' => (string) $member->id,
            'mailbox_id' => (int) $mailbox->id,
            'provider_connection_id' => (int) $mailbox->provider_connection_id,
            'provider_connection_version' => (int) $mailbox->connection->authorization_version,
            'recipient_snapshot_hash' => $recipientSnapshotHash,
            'strategy_projection' => $strategyProjection,
            'strategy_projection_hash' => $strategyContract['projection_hash'],
        ]);

        return [
            'gtm_experiment_assignment_id' => $assignment->id,
            'gtm_experiment_arm_id' => $assignment->gtm_experiment_arm_id,
            'objective_run_arm_draft_id' => $draft->id,
            'strategy_version_id' => $strategy->id,
            'audience_snapshot_member_id' => $member->id,
            'account_id' => $account->id,
            'person_id' => $person->id,
            'offer_version_id' => $run->objectiveVersion->offer_version_id,
            'conversion_definition_version_id' => $run->objectiveVersion->conversion_definition_version_id,
            'selected_claim_ids' => $claimIds,
            'selected_evidence_ids' => $evidenceIds,
            'selected_claim_safety_hashes' => $claimSafetyHashes,
            'offer_content_hash' => $offerContract['content_hash'],
            'offer_contract_hash' => $offerContract['contract_hash'],
            'cta_destination_url' => $offerContract['destination_url'],
            'cta_response_route' => $offerContract['response_route'],
            'action_kind' => ExecutionPlanActionKind::EmailNewThread,
            'position' => 1,
            'destination_email_hash' => hash('sha256', $email),
            'destination_email_address' => $email,
            'subject' => $rendered['subject'],
            'body_text' => $rendered['body'],
            'body_html' => null,
            'template_hash' => $templateHash,
            'recipient_snapshot_hash' => $recipientSnapshotHash,
            'content_hash' => $contentHash,
            'mailbox_id' => $mailbox->id,
            'provider_connection_id' => $mailbox->provider_connection_id,
            'provider_connection_version' => (int) $mailbox->connection->authorization_version,
            'sender_address_snapshot' => $sender['email'],
            'sender_display_name_snapshot' => $sender['name'],
            'reply_to_snapshot' => $sender['email'],
            'scheduled_not_before' => $notBefore,
            'scheduled_not_after' => $run->objectiveVersion->ends_at,
            'policy_input_hash' => $policyInputHash,
        ];
    }

    /** @param array{projection:array<string,mixed>,projection_hash:string} $strategyContract */
    private function draftStrategyContractMatches(
        ObjectiveRunArmDraft $draft,
        array $strategyContract,
    ): bool {
        $claimManifest = $draft->claim_manifest;
        $ctaManifest = $draft->cta_manifest;
        if (! is_array($claimManifest) || ! is_array($ctaManifest)
            || ($claimManifest['template_version'] ?? null) !== DeterministicMessageTemplate::VERSION
            || ! is_array($claimManifest['strategy_projection'] ?? null)
            || ! is_string($claimManifest['strategy_projection_hash'] ?? null)
            || ! is_string($claimManifest['message_pattern'] ?? null)
            || ! is_string($claimManifest['evidence_choice'] ?? null)
            || ! is_string($claimManifest['offer_contract_hash'] ?? null)
            || ($claimManifest['abstention_reasons'] ?? null) !== []
            || ! is_array($ctaManifest['strategy_projection'] ?? null)
            || ! is_string($ctaManifest['strategy_projection_hash'] ?? null)
            || ($ctaManifest['kind'] ?? null) !== 'offer_action'
            || ($ctaManifest['strategy_action'] ?? null) !== ($strategyContract['projection']['cta_kind'] ?? null)
            || ($ctaManifest['text'] ?? null) !== ($strategyContract['projection']['cta_text'] ?? null)) {
            return false;
        }

        $expectedHash = CanonicalObjectivePayload::hash($strategyContract);
        $expectedTemplateHash = CanonicalObjectivePayload::hash([
            'template_version' => DeterministicMessageTemplate::VERSION,
            'strategy_projection' => $strategyContract['projection'],
            'strategy_projection_hash' => $strategyContract['projection_hash'],
            'message_pattern' => $claimManifest['message_pattern'],
            'evidence_choice' => $claimManifest['evidence_choice'],
            'offer_contract_hash' => $claimManifest['offer_contract_hash'],
        ]);
        $expectedOutputHash = CanonicalObjectivePayload::hash([
            'template_version' => DeterministicMessageTemplate::VERSION,
            'strategy_projection' => $strategyContract['projection'],
            'strategy_projection_hash' => $strategyContract['projection_hash'],
            'message_pattern' => $claimManifest['message_pattern'],
            'evidence_choice' => $claimManifest['evidence_choice'],
            'cta_kind' => $ctaManifest['kind'],
            'abstain' => false,
            'abstention_reasons' => [],
        ]);

        return hash_equals($expectedHash, CanonicalObjectivePayload::hash([
            'projection' => $claimManifest['strategy_projection'],
            'projection_hash' => $claimManifest['strategy_projection_hash'],
        ])) && hash_equals($expectedHash, CanonicalObjectivePayload::hash([
            'projection' => $ctaManifest['strategy_projection'],
            'projection_hash' => $ctaManifest['strategy_projection_hash'],
        ])) && hash_equals($expectedTemplateHash, (string) $draft->template_hash)
            && hash_equals($expectedOutputHash, (string) $draft->output_hash);
    }

    private function currentReasonForMember(
        AudienceSnapshotMember $member,
        int $workspaceId,
        bool $lock,
    ): ?ReasonToContactVersion {
        if ($member->account_id === null
            || $member->qualification_decision_id === null
            || $member->reason_to_contact_version_id === null
            || ! is_string($member->reason_content_hash)
            || $member->reason_content_hash === '') {
            return null;
        }

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
            ->where(fn ($reason) => $reason->whereNull('expires_at')->orWhere('expires_at', '>', now()));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @param list<string> $claimIds */
    private function assertClaimsBelongToReason(
        ReasonToContactVersion $reason,
        array $claimIds,
        int $workspaceId,
    ): void {
        $claimIds = array_values(array_unique(array_filter(
            array_map('strval', $claimIds),
            static fn (string $claimId): bool => $claimId !== '',
        )));
        if ($claimIds === []) {
            throw ValidationException::withMessages([
                'reason_to_contact' => 'Every selected Claim must belong to the exact Reason to Contact.',
            ]);
        }

        $linkedClaimIds = ReasonToContactClaim::query()
            ->where('workspace_id', $workspaceId)
            ->where('reason_to_contact_version_id', $reason->id)
            ->whereIn('claim_id', $claimIds)
            ->lockForUpdate()
            ->pluck('claim_id')
            ->map(static fn (mixed $claimId): string => (string) $claimId)
            ->unique()
            ->values()
            ->all();

        if (array_diff($claimIds, $linkedClaimIds) !== []) {
            throw ValidationException::withMessages([
                'reason_to_contact' => 'Every selected Claim must belong to the exact Reason to Contact.',
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @param  array<string, array{projection:array<string,mixed>,projection_hash:string}>  $strategyContracts
     */
    private function assertActionIntegrity(
        ObjectiveRun $run,
        ObjectiveVersion $version,
        array $actions,
        array $strategyContracts,
    ): void {
        $maximumDays = (int) config('objectives.safety.maximum_horizon_days');
        if ($maximumDays < 1
            || ! $version->starts_at->lessThan($version->ends_at)
            || ! $version->ends_at->isFuture()
            || $version->ends_at->greaterThan($version->starts_at->copy()->addDays($maximumDays))) {
            throw ValidationException::withMessages([
                'horizon' => 'The Objective horizon is no longer a valid open pilot window.',
            ]);
        }

        $assignmentIds = $run->experiment->assignments()->pluck('id');
        $memberIds = GtmExperimentAssignmentMember::query()
            ->where('workspace_id', $run->workspace_id)
            ->whereIn('gtm_experiment_assignment_id', $assignmentIds)
            ->orderBy('audience_snapshot_member_id')
            ->pluck('audience_snapshot_member_id');
        foreach ($memberIds as $memberId) {
            $member = AudienceSnapshotMember::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('audience_snapshot_id', $run->audience_snapshot_id)
                ->where('result', 'included')
                ->whereKey($memberId)
                ->lockForUpdate()
                ->first();
            $reason = $member === null
                ? null
                : $this->currentReasonForMember($member, (int) $run->workspace_id, true);
            if ($reason === null) {
                throw ValidationException::withMessages([
                    'reason_to_contact' => 'The selected Reason to Contact changed before exact action creation.',
                ]);
            }
        }

        foreach ($actions as $action) {
            $strategy = StrategyVersion::query()
                ->where('workspace_id', $run->workspace_id)
                ->whereKey($action['strategy_version_id'])
                ->lockForUpdate()
                ->first();
            $expectedStrategyContract = $strategyContracts[(string) $action['strategy_version_id']] ?? null;
            try {
                $currentStrategyContract = $strategy === null ? null : $this->templates->strategyContract($strategy);
            } catch (DraftMessageValidationException) {
                $currentStrategyContract = null;
            }
            if (! is_array($expectedStrategyContract) || ! is_array($currentStrategyContract)
                || ! hash_equals(
                    CanonicalObjectivePayload::hash($expectedStrategyContract),
                    CanonicalObjectivePayload::hash($currentStrategyContract),
                )) {
                throw ValidationException::withMessages([
                    'strategy' => 'The frozen outbound Strategy changed before exact action persistence.',
                ]);
            }

            $notBefore = Carbon::parse($action['scheduled_not_before']);
            $notAfter = Carbon::parse($action['scheduled_not_after']);
            if (! $notBefore->lessThan($notAfter)
                || ! $notAfter->isFuture()
                || ! $notAfter->equalTo($version->ends_at)) {
                throw ValidationException::withMessages([
                    'actions' => 'An exact action no longer has a valid open scheduling window.',
                ]);
            }

            $member = AudienceSnapshotMember::query()
                ->where('workspace_id', $run->workspace_id)
                ->whereKey($action['audience_snapshot_member_id'])
                ->where('account_id', $action['account_id'])
                ->where('person_id', $action['person_id'])
                ->lockForUpdate()
                ->first();
            $reason = $member === null
                ? null
                : $this->currentReasonForMember($member, (int) $run->workspace_id, true);
            if ($reason === null) {
                throw ValidationException::withMessages([
                    'reason_to_contact' => 'The selected Reason to Contact changed before exact action creation.',
                ]);
            }
            $this->assertClaimsBelongToReason(
                $reason,
                array_values(array_map('strval', $action['selected_claim_ids'])),
                (int) $run->workspace_id,
            );
        }
    }
}
