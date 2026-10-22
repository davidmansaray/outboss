<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Campaigns\DTOs\GovernedCampaignAction;
use App\Domain\Campaigns\Services\CreateGovernedCampaign;
use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Domain\Mail\Exceptions\SenderIdentityUnavailable;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\Objectives\DTOs\ApprovalCheckpointDecision;
use App\Domain\Objectives\Enums\AudienceMemberResult;
use App\Domain\Objectives\Enums\ExecutionActionEventType;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Domain\Objectives\Enums\ExecutionPlanCompilationState;
use App\Domain\Objectives\Enums\ExecutionPlanStatus;
use App\Domain\Objectives\Enums\GtmExperimentStatus;
use App\Domain\Objectives\Enums\ObjectiveRunArmDraftState;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseName;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseState;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Domain\Objectives\Enums\PolicyEvaluationStage;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\DeterministicMessageTemplate;
use App\Domain\Objectives\Support\ExecutionPlanFingerprint;
use App\Domain\Objectives\Support\MessageEvidenceContract;
use App\Models\Account;
use App\Models\AudienceSnapshotMember;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\ExecutionPlanActionCompilation;
use App\Models\ExecutionPlanCampaign;
use App\Models\ExecutionPlanCompilation;
use App\Models\GtmExperiment;
use App\Models\GtmExperimentArm;
use App\Models\GtmExperimentAssignment;
use App\Models\GtmExperimentAssignmentMember;
use App\Models\Mailbox;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use App\Models\Person;
use App\Models\ProviderConnection;
use App\Models\StrategyVersion;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Compile one exact approved plan into an all-or-nothing draft Campaign graph. */
class CompileExecutionPlan
{
    public const VERSION = 'execution_plan_compiler_v1';

    public function __construct(
        private readonly CreateGovernedCampaign $campaigns,
        private readonly GovernedCampaignInputGuard $frozenCampaignInputs,
        private readonly MailboxSenderIdentityService $senderIdentities,
        private readonly DeterministicMessageTemplate $templates,
        private readonly MessageEvidenceContract $evidence,
        private readonly RenderSequenceMessage $renderer,
        private readonly BuildObjectiveDraftPrompt $prompts,
        private readonly GovernedExecutionCheckpoint $approvalCheckpoint,
        private readonly RecordExecutionActionEvent $actionEvents,
    ) {}

    public function handle(ExecutionPlan $plan, User $actor): ExecutionPlanCompilation
    {
        $failed = null;

        try {
            $result = DB::transaction(function () use ($plan, $actor, &$failed): ExecutionPlanCompilation|ApprovalCheckpointDecision {
                $workspaceId = $this->authorisedWorkspaceId($plan, $actor);
                $lockedPlan = ExecutionPlan::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey($plan->id)
                    ->lockForUpdate()
                    ->first();
                if (! $lockedPlan instanceof ExecutionPlan) {
                    throw ValidationException::withMessages([
                        'plan' => 'The execution plan is not available in the actor workspace.',
                    ]);
                }

                /** @var Collection<int, ExecutionPlanAction> $actions */
                $actions = ExecutionPlanAction::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('execution_plan_id', $lockedPlan->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $actionPayloads = $this->assertApprovedFingerprint($lockedPlan, $actions);
                // A retry of human compilation reads its completed artifact only.
                // It neither issues new authority nor re-enters draft validation
                // against the now-frozen campaign. Launch must revalidate separately.
                if ($lockedPlan->getAttribute('origin') === ExecutionOrigin::Human) {
                    $succeeded = ExecutionPlanCompilation::query()->where('workspace_id', $workspaceId)
                        ->where('execution_plan_id', $lockedPlan->id)->where('state', ExecutionPlanCompilationState::Succeeded)
                        ->lockForUpdate()->first();
                    if ($succeeded !== null) {
                        if (! hash_equals($succeeded->input_hash, $this->compilationInputHash($lockedPlan, $actionPayloads))) {
                            throw ValidationException::withMessages(['plan' => 'The compiled human review fingerprint changed.']);
                        }
                        $this->assertSuccessfulReplayComplete($succeeded, $lockedPlan, $actions->count());
                        app(VerifyHumanCompilationArtifact::class)->handle($lockedPlan, $succeeded);

                        return $succeeded->load(['campaigns' => fn ($query) => $query->lockForUpdate(), 'actions' => fn ($query) => $query->lockForUpdate()]);
                    }
                }
                $approval = $this->approvalCheckpoint->forPlan(
                    $lockedPlan,
                    PolicyEvaluationStage::Approval,
                    true,
                );
                if (! $approval->allowed()) {
                    return $approval;
                }
                $inputHash = $this->compilationInputHash($lockedPlan, $actionPayloads);
                $succeeded = ExecutionPlanCompilation::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('execution_plan_id', $lockedPlan->id)
                    ->where('state', ExecutionPlanCompilationState::Succeeded->value)
                    ->lockForUpdate()
                    ->first();
                if ($succeeded instanceof ExecutionPlanCompilation) {
                    if (! hash_equals($succeeded->input_hash, $inputHash)) {
                        throw ValidationException::withMessages([
                            'plan' => 'The successful compilation fingerprint conflicts with the current approved plan.',
                        ]);
                    }
                    $this->assertSuccessfulReplayComplete($succeeded, $lockedPlan, $actions->count());

                    return $succeeded->load(['campaigns' => fn ($query) => $query->lockForUpdate(), 'actions' => fn ($query) => $query->lockForUpdate()]);
                }

                if ($lockedPlan->status !== ExecutionPlanStatus::Approved
                    || $actions->contains(fn (ExecutionPlanAction $action): bool => $action->state !== ExecutionPlanActionState::Approved)) {
                    throw ValidationException::withMessages([
                        'plan' => 'Only an exactly approved plan and action set can be compiled.',
                    ]);
                }

                $attempt = (int) ExecutionPlanCompilation::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('execution_plan_id', $lockedPlan->id)
                    ->max('attempt') + 1;
                $failed = [
                    'workspace_id' => $workspaceId,
                    'execution_plan_id' => (string) $lockedPlan->id,
                    'attempt' => $attempt,
                    'input_hash' => $inputHash,
                    'created_by_user_id' => (int) $actor->id,
                ];
                $compilation = ExecutionPlanCompilation::query()->create([
                    'id' => (string) Str::uuid(),
                    ...$failed,
                    'state' => ExecutionPlanCompilationState::Started,
                    'started_at' => now(),
                ]);

                return $this->frozenCampaignInputs->runCompilerMutation(
                    $compilation,
                    function ($capability) use ($actor, $workspaceId, $lockedPlan, $actions, $inputHash, $compilation, &$failed): ExecutionPlanCompilation {
                        if ($lockedPlan->getAttribute('origin') === ExecutionOrigin::Human) {
                            [$run, $manifest] = app(CompileHumanCampaignProjection::class)->handle($lockedPlan, $actions, $compilation, $capability);
                        } else {
                            [$run, $experiment] = $this->lockPlanDependencies($lockedPlan);
                            $armIds = $experiment->arms()->orderBy('id')->pluck('id')->map(strval(...))->all();
                            $actionsByArm = $actions->groupBy(fn (ExecutionPlanAction $action): string => (string) $action->gtm_experiment_arm_id);
                            $compiledArmIds = $actionsByArm->keys()->map(strval(...))->sort()->values()->all();
                            $expectedArmIds = collect($armIds)->sort()->values()->all();
                            if ($compiledArmIds !== $expectedArmIds
                                || count($armIds) !== (int) $lockedPlan->arm_count
                                || count($armIds) < 1
                                || count($armIds) > 2) {
                                throw ValidationException::withMessages([
                                    'arms' => 'The approved plan no longer has one complete action set for every experiment arm.',
                                ]);
                            }

                            $manifest = [];
                            foreach ($expectedArmIds as $armId) {
                                $arm = GtmExperimentArm::query()
                                    ->where('workspace_id', $workspaceId)
                                    ->where('gtm_experiment_id', $experiment->id)
                                    ->whereKey($armId)
                                    ->lockForUpdate()
                                    ->firstOrFail();
                                $armActions = $actionsByArm->get($armId);
                                if (! $armActions instanceof Collection || $armActions->isEmpty()) {
                                    throw ValidationException::withMessages(['arms' => 'An approved experiment arm has no exact actions.']);
                                }

                                $campaignActions = [];
                                foreach ($armActions->sortBy('id') as $action) {
                                    $campaignActions[] = $this->governedAction($lockedPlan, $run, $arm, $action);
                                }
                                $result = $this->campaigns->handle(
                                    actor: $actor,
                                    workspaceId: $workspaceId,
                                    name: $this->campaignName($lockedPlan, $arm),
                                    actions: $campaignActions,
                                    capability: $capability,
                                );
                                $campaignLink = ExecutionPlanCampaign::query()->create([
                                    'id' => (string) Str::uuid(),
                                    'workspace_id' => $workspaceId,
                                    'execution_plan_compilation_id' => $compilation->id,
                                    'execution_plan_id' => $lockedPlan->id,
                                    'gtm_experiment_arm_id' => $arm->id,
                                    'campaign_id' => $result->campaign->id,
                                    'sequence_version_id' => $result->sequenceVersion->id,
                                    'sequence_step_id' => $result->sequenceStep->id,
                                ]);
                                $compiledActions = [];
                                foreach ($campaignActions as $campaignAction) {
                                    $recipient = $result->recipientsByActionId[$campaignAction->actionId] ?? null;
                                    if ($recipient === null) {
                                        throw ValidationException::withMessages([
                                            'actions' => 'The compiled recipient map is incomplete.',
                                        ]);
                                    }
                                    $actionLink = ExecutionPlanActionCompilation::query()->create([
                                        'id' => (string) Str::uuid(),
                                        'workspace_id' => $workspaceId,
                                        'execution_plan_compilation_id' => $compilation->id,
                                        'execution_plan_id' => $lockedPlan->id,
                                        'execution_plan_action_id' => $campaignAction->actionId,
                                        'execution_plan_campaign_id' => $campaignLink->id,
                                        'campaign_id' => $result->campaign->id,
                                        'campaign_recipient_id' => $recipient->id,
                                        'sequence_step_id' => $result->sequenceStep->id,
                                        'compiled_input_hash' => $campaignAction->actionFingerprint,
                                        'rendered_content_hash' => $campaignAction->contentHash,
                                    ]);
                                    $this->actionEvents->handle(
                                        $armActions->firstOrFail(fn (ExecutionPlanAction $candidate): bool => (string) $candidate->id === $campaignAction->actionId),
                                        ExecutionActionEventType::Compiled,
                                        $recipient,
                                    );
                                    $compiledActions[] = [
                                        'action_id' => $campaignAction->actionId,
                                        'action_compilation_id' => (string) $actionLink->id,
                                        'campaign_recipient_id' => (int) $recipient->id,
                                        'compiled_input_hash' => $campaignAction->actionFingerprint,
                                        'rendered_content_hash' => $campaignAction->contentHash,
                                    ];
                                }
                                $capability->seal($result->campaign);
                                $manifest[] = [
                                    'arm_id' => (string) $arm->id,
                                    'execution_plan_campaign_id' => (string) $campaignLink->id,
                                    'campaign_id' => (int) $result->campaign->id,
                                    'sequence_version_id' => (int) $result->sequenceVersion->id,
                                    'sequence_step_id' => (int) $result->sequenceStep->id,
                                    'actions' => $compiledActions,
                                ];
                            }

                        }

                        $this->assertApprovedFingerprint($lockedPlan, $actions->each->refresh());
                        $outputHash = CanonicalObjectivePayload::hash([
                            'version' => self::VERSION,
                            'workspace_id' => $workspaceId,
                            'execution_plan_id' => (string) $lockedPlan->id,
                            'input_hash' => $inputHash,
                            'campaigns' => $manifest,
                        ]);
                        DB::table('execution_plan_compilations')->where('id', $compilation->id)->update([
                            'succeeded_plan_id' => $lockedPlan->id,
                            'state' => ExecutionPlanCompilationState::Succeeded->value,
                            'output_hash' => $outputHash,
                            'finished_at' => now(),
                            'updated_at' => now(),
                        ]);
                        DB::table('execution_plan_actions')->where('workspace_id', $workspaceId)
                            ->where('execution_plan_id', $lockedPlan->id)
                            ->update(['state' => ExecutionPlanActionState::Compiled->value, 'updated_at' => now()]);
                        DB::table('execution_plans')->where('workspace_id', $workspaceId)->where('id', $lockedPlan->id)->update([
                            'status' => ExecutionPlanStatus::Compiled->value,
                            'compilation_manifest' => json_encode([
                                'version' => 'execution_plan_compilation_v1',
                                'compilation_id' => (string) $compilation->id,
                                'input_hash' => $inputHash,
                                'output_hash' => $outputHash,
                                'campaigns' => $manifest,
                            ], JSON_THROW_ON_ERROR),
                            'compiled_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $this->advanceRunProjection($run, $lockedPlan, $outputHash);

                        $failed = null;

                        return $compilation->fresh(['campaigns', 'actions']) ?? $compilation;
                    },
                );
            }, 3);
            if ($result instanceof ApprovalCheckpointDecision) {
                throw ValidationException::withMessages([
                    'plan' => 'Only an exactly approved plan and current immutable per-action grant set can be compiled ('.$result->primaryCode().').',
                ]);
            }

            return $result;
        } catch (Throwable $exception) {
            if (is_array($failed)) {
                $this->recordFailure($failed);
            }

            throw $exception;
        }
    }

    private function authorisedWorkspaceId(ExecutionPlan $plan, User $actor): int
    {
        $workspaceId = (int) $actor->currentWorkspace()->getKey();
        if ($workspaceId < 1
            || (int) $plan->workspace_id !== $workspaceId
            || ! WorkspaceMembership::query()->where('workspace_id', $workspaceId)
                ->where('user_id', $actor->id)
                ->where('role', WorkspaceMembership::ROLE_OWNER)
                ->exists()) {
            throw ValidationException::withMessages([
                'plan' => 'Only an owner of the plan workspace may compile an approved plan.',
            ]);
        }

        return $workspaceId;
    }

    /**
     * @param  Collection<int, ExecutionPlanAction>  $actions
     * @return list<array<string, mixed>>
     */
    private function assertApprovedFingerprint(ExecutionPlan $plan, Collection $actions): array
    {
        if ($actions->isEmpty() || $actions->count() !== (int) $plan->action_count) {
            throw ValidationException::withMessages(['actions' => 'The approved action count changed before compilation.']);
        }
        $payloads = [];
        foreach ($actions as $action) {
            $payload = $this->actionPayload($action);
            $fingerprint = ExecutionPlanFingerprint::action($payload);
            if (! hash_equals((string) $action->action_fingerprint, $fingerprint)) {
                throw ValidationException::withMessages(['actions' => 'An approved action fingerprint changed before compilation.']);
            }
            $payloads[] = $payload;
        }
        $planHash = ExecutionPlanFingerprint::plan(
            (string) $plan->id,
            (string) $plan->canonical_payload_hash,
            $payloads,
        );
        if (! hash_equals((string) $plan->plan_hash, $planHash)) {
            throw ValidationException::withMessages(['plan' => 'The exact approved plan hash changed before compilation.']);
        }

        return $payloads;
    }

    /** @return array{ObjectiveRun, GtmExperiment} */
    private function lockPlanDependencies(ExecutionPlan $plan): array
    {
        $run = ObjectiveRun::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($plan->objective_run_id)->lockForUpdate()->first();
        if (! $run instanceof ObjectiveRun
            || ! hash_equals((string) $run->objective_version_id, (string) $plan->objective_version_id)
            || ! hash_equals((string) $run->audience_snapshot_id, (string) $plan->audience_snapshot_id)
            || ! hash_equals((string) $run->gtm_experiment_id, (string) $plan->gtm_experiment_id)
            || in_array($run->state, [
                ObjectiveRunState::Cancelled,
                ObjectiveRunState::Superseded,
                ObjectiveRunState::Failed,
            ], true)) {
            throw ValidationException::withMessages(['run' => 'The approved Objective Run is no longer compilable.']);
        }
        $objective = $run->objective()->where('workspace_id', $plan->workspace_id)->lockForUpdate()->first();
        if ($objective === null || ! hash_equals((string) $objective->current_plan_id, (string) $plan->id)) {
            throw ValidationException::withMessages(['plan' => 'The approved plan is no longer the Objective current plan.']);
        }
        $experiment = GtmExperiment::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($plan->gtm_experiment_id)->lockForUpdate()->first();
        if (! $experiment instanceof GtmExperiment
            || ! hash_equals((string) $experiment->objective_version_id, (string) $plan->objective_version_id)
            || ! hash_equals((string) $experiment->audience_snapshot_id, (string) $plan->audience_snapshot_id)
            || ! in_array($experiment->status, [GtmExperimentStatus::Assigned, GtmExperimentStatus::Approved], true)) {
            throw ValidationException::withMessages(['experiment' => 'The approved experiment is no longer eligible for compilation.']);
        }

        return [$run, $experiment];
    }

    private function governedAction(
        ExecutionPlan $plan,
        ObjectiveRun $run,
        GtmExperimentArm $arm,
        ExecutionPlanAction $action,
    ): GovernedCampaignAction {
        if ((int) $action->workspace_id !== (int) $plan->workspace_id
            || ! hash_equals((string) $action->execution_plan_id, (string) $plan->id)
            || ! hash_equals((string) $action->gtm_experiment_arm_id, (string) $arm->id)
            || (int) $action->position !== 1
            || $action->body_html !== null) {
            throw ValidationException::withMessages(['actions' => 'The approved action is not a supported one-message email action.']);
        }

        $assignment = GtmExperimentAssignment::query()->where('workspace_id', $plan->workspace_id)
            ->where('gtm_experiment_id', $plan->gtm_experiment_id)
            ->where('gtm_experiment_arm_id', $arm->id)
            ->where('audience_snapshot_id', $plan->audience_snapshot_id)
            ->where('account_id', $action->account_id)
            ->whereKey($action->gtm_experiment_assignment_id)
            ->lockForUpdate()->first();
        $member = AudienceSnapshotMember::query()->where('workspace_id', $plan->workspace_id)
            ->where('audience_snapshot_id', $plan->audience_snapshot_id)
            ->where('account_id', $action->account_id)
            ->where('person_id', $action->person_id)
            ->where('result', AudienceMemberResult::Included->value)
            ->whereKey($action->audience_snapshot_member_id)
            ->lockForUpdate()->first();
        if (! $assignment instanceof GtmExperimentAssignment || ! $member instanceof AudienceSnapshotMember) {
            throw ValidationException::withMessages(['actions' => 'An approved assignment or audience member is missing.']);
        }
        $memberLink = GtmExperimentAssignmentMember::query()->where('workspace_id', $plan->workspace_id)
            ->where('gtm_experiment_assignment_id', $assignment->id)
            ->where('audience_snapshot_member_id', $member->id)
            ->where('person_id', $action->person_id)
            ->lockForUpdate()->first();
        if (! $memberLink instanceof GtmExperimentAssignmentMember
            || ! hash_equals((string) $memberLink->member_hash, (string) $member->member_hash)) {
            throw ValidationException::withMessages(['actions' => 'The approved assignment member snapshot changed.']);
        }

        $person = Person::query()->where('workspace_id', $plan->workspace_id)
            ->where('account_id', $action->account_id)->whereKey($action->person_id)->lockForUpdate()->first();
        $account = Account::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($action->account_id)->lockForUpdate()->first();
        if (! $person instanceof Person || ! $account instanceof Account) {
            throw ValidationException::withMessages(['actions' => 'An approved Person or Account is missing.']);
        }
        $email = EmailNormalizer::normalize((string) $person->email_address);
        $snapshot = $this->templates->recipientSnapshot($person->first_name, $person->full_name, (string) $account->name);
        $recipientHash = CanonicalObjectivePayload::hash([
            'person_id' => (int) $person->id,
            'account_id' => (int) $account->id,
            'member_hash' => (string) $member->member_hash,
            'destination_email_hash' => hash('sha256', $email),
            ...$snapshot,
        ]);
        if (! EmailNormalizer::isValid($email)
            || ! hash_equals((string) $action->destination_email_address, $email)
            || ! hash_equals((string) $action->destination_email_hash, hash('sha256', $email))
            || ! hash_equals((string) $action->recipient_snapshot_hash, $recipientHash)) {
            throw ValidationException::withMessages(['actions' => 'The approved recipient snapshot changed.']);
        }

        $mailbox = Mailbox::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($action->mailbox_id)->lockForUpdate()->first();
        $connection = ProviderConnection::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($action->provider_connection_id)->lockForUpdate()->first();
        if (! $mailbox instanceof Mailbox || ! $connection instanceof ProviderConnection
            || (int) $mailbox->provider_connection_id !== (int) $connection->id
            || $this->value($mailbox->status) !== 'connected'
            || $mailbox->sending_paused_at !== null
            || $this->value($connection->status) !== 'connected'
            || (int) $connection->authorization_version !== (int) $action->provider_connection_version) {
            throw ValidationException::withMessages(['mailbox' => 'The approved mailbox or Provider Connection changed.']);
        }
        try {
            $sender = $this->senderIdentities->effective($mailbox);
        } catch (SenderIdentityUnavailable) {
            throw ValidationException::withMessages(['mailbox' => 'The approved sender identity is no longer available.']);
        }
        if (! hash_equals((string) $action->sender_address_snapshot, $sender['email'])
            || ! $this->nullableEquals($action->sender_display_name_snapshot, $sender['name'])
            || ! hash_equals((string) $action->reply_to_snapshot, $sender['email'])) {
            throw ValidationException::withMessages(['mailbox' => 'The approved sender identity snapshot changed.']);
        }

        $draft = ObjectiveRunArmDraft::query()->where('workspace_id', $plan->workspace_id)
            ->where('objective_run_id', $run->id)->where('gtm_experiment_arm_id', $arm->id)
            ->whereKey($action->objective_run_arm_draft_id)->lockForUpdate()->first();
        $strategy = StrategyVersion::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($action->strategy_version_id)->lockForUpdate()->first();
        if (! $draft instanceof ObjectiveRunArmDraft || $draft->state !== ObjectiveRunArmDraftState::Succeeded
            || ! $strategy instanceof StrategyVersion
            || ! hash_equals((string) $arm->strategy_version_id, (string) $strategy->id)
            || ! hash_equals((string) $arm->offer_version_id, (string) $action->offer_version_id)
            || ! hash_equals((string) $plan->offer_version_id, (string) $action->offer_version_id)
            || ! hash_equals((string) $plan->conversion_definition_version_id, (string) $action->conversion_definition_version_id)) {
            throw ValidationException::withMessages(['actions' => 'An approved Strategy, Offer, or arm draft changed.']);
        }
        $contract = $this->prompts->handle($arm)['contract'];
        $strategyContract = $contract['strategy'] ?? null;
        $offerContract = $contract['offer'] ?? null;
        if (! is_array($strategyContract) || ! is_array($offerContract)) {
            throw ValidationException::withMessages(['actions' => 'The approved outbound contract is unavailable.']);
        }

        $claimIds = array_map('strval', $action->selected_claim_ids);
        if (count($claimIds) !== 1) {
            throw ValidationException::withMessages(['actions' => 'Each approved action must select exactly one Claim.']);
        }
        $claimId = $claimIds[0];
        $frozen = collect($member->evidence_manifest)->first(
            fn (array $candidate): bool => hash_equals((string) ($candidate['claim_id'] ?? ''), $claimId),
        );
        $verified = $frozen !== null
            ? $this->evidence->verifyFrozen($frozen, (int) $plan->workspace_id, (int) $account->id)
            : null;
        if (! is_array($verified)) {
            throw ValidationException::withMessages(['actions' => 'The approved Claim evidence is missing or stale.']);
        }
        $supportingEvidence = $verified['supporting_evidence'] ?? null;
        if (! is_array($supportingEvidence)) {
            throw ValidationException::withMessages(['actions' => 'The approved Claim evidence manifest is incomplete.']);
        }
        $evidenceIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $edge): string => is_array($edge) ? (string) ($edge['evidence_id'] ?? '') : '',
            $supportingEvidence,
        ))));
        sort($evidenceIds, SORT_STRING);
        if ($evidenceIds !== array_map('strval', $action->selected_evidence_ids)
            || $action->selected_claim_safety_hashes !== [$claimId => (string) $verified['safety_hash']]) {
            throw ValidationException::withMessages(['actions' => 'The approved Claim safety manifest changed.']);
        }

        $messageTemplates = $this->templates->render($verified, $offerContract, $strategyContract);
        $rendered = $this->renderer->previewTemplates(
            $messageTemplates['subject'],
            $messageTemplates['body'],
            $snapshot,
        );
        $claimManifest = $draft->claim_manifest;
        $templateHash = CanonicalObjectivePayload::hash([
            'template_version' => DeterministicMessageTemplate::VERSION,
            'strategy_projection' => $strategyContract['projection'],
            'strategy_projection_hash' => $strategyContract['projection_hash'],
            'message_pattern' => is_array($claimManifest) ? $claimManifest['message_pattern'] ?? null : null,
            'evidence_choice' => is_array($claimManifest) ? $claimManifest['evidence_choice'] ?? null : null,
            'claim_id' => $claimId,
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
        if (! hash_equals((string) $action->offer_content_hash, (string) $offerContract['content_hash'])
            || ! hash_equals((string) $action->offer_contract_hash, (string) $offerContract['contract_hash'])
            || ! hash_equals((string) $action->cta_destination_url, (string) $offerContract['destination_url'])
            || ! hash_equals((string) $action->cta_response_route, (string) $offerContract['response_route'])
            || ! hash_equals((string) $action->template_hash, $templateHash)
            || ! hash_equals((string) $action->subject, $rendered['subject'])
            || ! hash_equals((string) $action->body_text, $rendered['body'])
            || ! hash_equals((string) $action->content_hash, $contentHash)) {
            throw ValidationException::withMessages(['actions' => 'The approved template or exact message bytes changed.']);
        }

        return new GovernedCampaignAction(
            actionId: (string) $action->id,
            personId: (int) $person->id,
            accountId: (int) $account->id,
            mailboxId: (int) $mailbox->id,
            emailAddress: $email,
            emailNormalized: $email,
            fullName: $person->full_name,
            firstName: $snapshot['first_name'],
            company: $snapshot['company'],
            subjectTemplate: $messageTemplates['subject'],
            bodyTemplate: $messageTemplates['body'],
            subject: $rendered['subject'],
            bodyText: $rendered['body'],
            actionFingerprint: (string) $action->action_fingerprint,
            contentHash: (string) $action->content_hash,
        );
    }

    /** @param list<array<string, mixed>> $actionPayloads */
    private function compilationInputHash(ExecutionPlan $plan, array $actionPayloads): string
    {
        return CanonicalObjectivePayload::hash([
            'version' => self::VERSION,
            'workspace_id' => (int) $plan->workspace_id,
            'execution_plan_id' => (string) $plan->id,
            'plan_hash' => (string) $plan->plan_hash,
            'action_fingerprints' => collect($actionPayloads)
                ->pluck('action_fingerprint')->map(strval(...))->sort()->values()->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function actionPayload(ExecutionPlanAction $action): array
    {
        $payload = [
            'id' => (string) $action->id,
            'execution_plan_id' => (string) $action->execution_plan_id,
        ];
        foreach (ExecutionPlanFingerprint::ACTION_FIELDS as $field) {
            $payload[$field] = $action->getAttribute($field);
        }
        $payload['action_fingerprint'] = (string) $action->action_fingerprint;

        return $payload;
    }

    private function assertSuccessfulReplayComplete(
        ExecutionPlanCompilation $compilation,
        ExecutionPlan $plan,
        int $actionCount,
    ): void {
        // Materialize locked rows: an aggregate must not reuse a caller's old read view.
        $campaignRows = ExecutionPlanCampaign::query()->where('workspace_id', $plan->workspace_id)
            ->where('execution_plan_compilation_id', $compilation->id)->lockForUpdate()->get(['id']);
        $compiledActionRows = ExecutionPlanActionCompilation::query()->where('workspace_id', $plan->workspace_id)
            ->where('execution_plan_compilation_id', $compilation->id)->lockForUpdate()->get(['id']);
        $expectedCampaigns = $plan->getAttribute('origin') === ExecutionOrigin::Human ? 1 : (int) $plan->arm_count;
        if (count($campaignRows) !== $expectedCampaigns || count($compiledActionRows) !== $actionCount) {
            throw ValidationException::withMessages(['plan' => 'The successful compilation lineage is incomplete.']);
        }
    }

    private function campaignName(ExecutionPlan $plan, GtmExperimentArm $arm): string
    {
        return 'Objective plan '.Str::lower(Str::substr((string) $plan->id, 0, 8)).' — '.$arm->label;
    }

    private function advanceRunProjection(ObjectiveRun $run, ExecutionPlan $plan, string $outputHash): void
    {
        DB::table('objective_run_phases')->where('workspace_id', $run->workspace_id)
            ->where('objective_run_id', $run->id)
            ->where('phase', ObjectiveRunPhaseName::AwaitApproval->value)
            ->where('state', ObjectiveRunPhaseState::Waiting->value)
            ->update([
                'state' => ObjectiveRunPhaseState::Succeeded->value,
                'output_hash' => $plan->plan_hash,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        DB::table('objective_run_phases')->updateOrInsert([
            'workspace_id' => $run->workspace_id,
            'objective_run_id' => $run->id,
            'phase' => ObjectiveRunPhaseName::CompileCampaign->value,
            'attempt_number' => 1,
        ], [
            'id' => (string) Str::uuid(),
            'state' => ObjectiveRunPhaseState::Succeeded->value,
            'input_hash' => $plan->plan_hash,
            'output_hash' => $outputHash,
            'fencing_token' => 0,
            'queued_at' => now(),
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('objective_run_phases')->updateOrInsert([
            'workspace_id' => $run->workspace_id,
            'objective_run_id' => $run->id,
            'phase' => ObjectiveRunPhaseName::Launch->value,
            'attempt_number' => 1,
        ], [
            'id' => (string) Str::uuid(),
            'state' => ObjectiveRunPhaseState::Waiting->value,
            'input_hash' => $outputHash,
            'fencing_token' => 0,
            'queued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('objective_runs')->where('workspace_id', $run->workspace_id)->where('id', $run->id)->update([
            'state' => ObjectiveRunState::Waiting->value,
            'current_phase' => ObjectiveRunPhaseName::Launch->value,
            'safe_error_code' => null,
            'safe_error_detail' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'heartbeat_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, int|string> $failed */
    private function recordFailure(array $failed): void
    {
        try {
            ExecutionPlanCompilation::query()->create([
                'id' => (string) Str::uuid(),
                ...$failed,
                'state' => ExecutionPlanCompilationState::Failed,
                'safe_error_code' => 'compilation_failed',
                'safe_error_detail' => 'The exact Campaign graph could not be compiled; no Campaign output was retained.',
                'started_at' => now(),
                'finished_at' => now(),
            ]);
        } catch (Throwable) {
            // Preserve the original safe failure. A competing retry may have
            // consumed the same attempt number while this transaction rolled
            // back, and must not hide the compiler error from its caller.
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
