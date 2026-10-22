<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Exceptions\AiTaskPolicyMismatch;
use App\Domain\Ai\Exceptions\SafeProviderException;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Ai\Services\ExecuteProviderOperation;
use App\Domain\Objectives\DTOs\ObjectiveDraftingResult;
use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Enums\ObjectiveRunArmDraftState;
use App\Domain\Objectives\Enums\ObjectiveRunDispatchState;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseName;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseState;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Domain\Objectives\Exceptions\DraftMessageValidationException;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Domain\Objectives\Support\DeterministicMessageTemplate;
use App\Models\AiBudgetEnvelope;
use App\Models\AiProviderOperation;
use App\Models\AiTaskSetting;
use App\Models\GtmExperimentArm;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use App\Models\ObjectiveRunDispatch;
use App\Models\ObjectiveRunPhase;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class DraftObjectiveMessages
{
    public function __construct(
        private readonly BuildObjectiveDraftPrompt $prompts,
        private readonly ValidateDraftedMessage $validator,
        private readonly ExecuteProviderOperation $provider,
        private readonly AiBudgetGateway $budget,
        private readonly FinalizeObjectiveRunBudget $finalizeBudget,
        private readonly DeterministicMessageTemplate $templates,
    ) {}

    public function handle(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        int $expectedFence,
    ): ObjectiveDraftingResult {
        $run = ObjectiveRun::query()->where('workspace_id', $run->workspace_id)
            ->whereKey($run->id)->with(['armDrafts.arm', 'budgetEnvelope', 'createdBy'])->firstOrFail();
        if ($run->getAttribute('origin') !== ExecutionOrigin::Ai) {
            throw new RuntimeException('human_run_cannot_generate_ai_messages');
        }
        $workspace = Workspace::query()->whereKey($run->workspace_id)->firstOrFail();
        $actor = $run->createdBy;
        if (! $actor instanceof User || ! $workspace->isOwner($actor)) {
            throw new RuntimeException('objective_run_actor_unavailable');
        }

        $ownsFence = $this->reserveAllArmOperations($run, $phase, $dispatch, $expectedFence);
        $run->refresh();
        if ($ownsFence) {
            foreach ($run->armDrafts->sortBy('gtm_experiment_arm_id') as $draft) {
                $this->draftArm($workspace, $actor, $run, $phase, $dispatch, $draft, $expectedFence);
            }
        }
        $states = $run->armDrafts()->get()->map(
            fn (ObjectiveRunArmDraft $draft): string => $draft->state->value,
        )->all();
        $runState = match (true) {
            collect($states)->contains(ObjectiveRunArmDraftState::AwaitingProvider->value) => ObjectiveRunState::Waiting,
            collect($states)->contains(ObjectiveRunArmDraftState::NeedsReview->value) => ObjectiveRunState::NeedsReview,
            collect($states)->contains(ObjectiveRunArmDraftState::Failed->value) => ObjectiveRunState::Failed,
            collect($states)->contains(ObjectiveRunArmDraftState::Cancelled->value) => ObjectiveRunState::Cancelled,
            default => ObjectiveRunState::Running,
        };
        $currentRunState = $run->fresh()->state;
        if (in_array($currentRunState, [ObjectiveRunState::Cancelled, ObjectiveRunState::Superseded], true)) {
            $runState = $currentRunState;
        }
        $budgetClosed = in_array($currentRunState, [ObjectiveRunState::Cancelled, ObjectiveRunState::Superseded], true)
            ? $this->finalizeBudget->cancelUnsent(
                $run,
                'objective_run_fenced',
                'The Objective run ended while provider work was being accounted for.',
            )
            : $this->finalizeBudget->closeTerminal($run);
        $waitingReason = null;
        if ($runState === ObjectiveRunState::Waiting) {
            $waitingReason = 'provider_reconciliation_pending';
        } elseif ($runState === ObjectiveRunState::Running && ! $budgetClosed) {
            // The provider results are durable, but the accounting boundary
            // is authoritative too. Keep this phase recoverable until every
            // arm run and the shared envelope can be closed successfully.
            $runState = ObjectiveRunState::Waiting;
            $waitingReason = 'budget_closure_pending';
        }
        $this->syncCosts($run);
        $hash = CanonicalObjectivePayload::hash(
            [
                'drafts' => $run->armDrafts()->orderBy('gtm_experiment_arm_id')->get()->map(fn (ObjectiveRunArmDraft $draft): array => [
                    'arm_id' => (string) $draft->gtm_experiment_arm_id,
                    'state' => $draft->state->value,
                    'input_hash' => (string) $draft->input_hash,
                    'output_hash' => (string) ($draft->output_hash ?? ''),
                    'template_hash' => (string) ($draft->template_hash ?? ''),
                    'safe_error_code' => (string) ($draft->safe_error_code ?? ''),
                ])->all(),
                'budget_closed' => $budgetClosed,
            ],
        );

        return new ObjectiveDraftingResult($runState, $hash, $waitingReason);
    }

    /**
     * Atomically reserve every arm before any arm may claim a provider call.
     *
     * This is also a durable crash boundary: a reclaimed phase can attach and
     * claim the same Reserved operation. An awaiting draft with no operation
     * is re-admitted only when its dedicated budget run contains no other
     * provider operation, which proves the paid boundary was never reached.
     */
    public function reserveAllArmOperations(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        int $expectedFence,
    ): bool {
        $run = ObjectiveRun::query()->where('workspace_id', $run->workspace_id)
            ->whereKey($run->id)->with('createdBy')->firstOrFail();
        $workspace = Workspace::query()->whereKey($run->workspace_id)->firstOrFail();
        $actor = $run->createdBy;
        if (! $actor instanceof User || ! $workspace->isOwner($actor)) {
            throw new RuntimeException('objective_run_actor_unavailable');
        }

        try {
            return $this->withOwnedDraftingContext(
                $run,
                $phase,
                $dispatch,
                $expectedFence,
                null,
                function (ObjectiveRun $lockedRun) use ($workspace, $actor): bool {
                    $drafts = ObjectiveRunArmDraft::query()
                        ->where('workspace_id', $workspace->id)
                        ->where('objective_run_id', $lockedRun->id)
                        ->orderBy('gtm_experiment_arm_id')
                        ->lockForUpdate()
                        ->get();
                    if (! $this->validatorVersionIsCurrent($lockedRun, $drafts->all())) {
                        foreach ($drafts as $draft) {
                            if (! in_array($draft->state, [
                                ObjectiveRunArmDraftState::NeedsReview,
                                ObjectiveRunArmDraftState::Failed,
                                ObjectiveRunArmDraftState::Cancelled,
                            ], true)) {
                                $this->rejectChangedValidatorLocked($draft);
                            }
                        }

                        return true;
                    }
                    $taskPolicy = AiTaskSetting::query()
                        ->where('workspace_id', $workspace->id)
                        ->where('task', AiTask::MessageDrafting->value)
                        ->first();
                    $frozenPolicyIsCurrent = $taskPolicy instanceof AiTaskSetting
                        && $drafts->every(fn (ObjectiveRunArmDraft $draft): bool => $draft->state->terminal()
                            || ((string) $draft->model_id === (string) $taskPolicy->model_id
                                && (int) $draft->task_policy_version === (int) $taskPolicy->policy_version));
                    if (! $frozenPolicyIsCurrent) {
                        foreach ($drafts as $draft) {
                            if (! $draft->state->terminal()) {
                                $this->rejectChangedPolicyLocked($draft);
                            }
                        }

                        return true;
                    }

                    foreach ($drafts as $draft) {
                        if ($draft->state->terminal()) {
                            continue;
                        }
                        $arm = GtmExperimentArm::query()->where('workspace_id', $workspace->id)
                            ->whereKey($draft->gtm_experiment_arm_id)->firstOrFail();
                        $prompt = $this->prompts->handle($arm);
                        if (! hash_equals((string) $draft->input_hash, $prompt['input_hash'])) {
                            $this->rejectDefinitelyNotSentLocked(
                                $draft,
                                'draft_input_changed',
                                'The frozen drafting input no longer matches this run.',
                            );

                            continue;
                        }

                        $key = $this->providerKey($lockedRun, $arm, $draft);
                        $attached = $draft->ai_provider_operation_id === null
                            ? null
                            : AiProviderOperation::query()
                                ->where('workspace_id', $workspace->id)
                                ->whereKey($draft->ai_provider_operation_id)
                                ->lockForUpdate()
                                ->first();
                        $keyed = AiProviderOperation::query()
                            ->where('workspace_id', $workspace->id)
                            ->where('idempotency_key', $key)
                            ->lockForUpdate()
                            ->first();

                        if (($attached !== null && ! hash_equals((string) $attached->idempotency_key, $key))
                            || ($attached !== null && $keyed !== null && (string) $attached->id !== (string) $keyed->id)) {
                            $this->reviewLocked($draft, 'draft_operation_mismatch', 'The durable provider operation does not match this frozen arm.');

                            continue;
                        }

                        if ($attached === null && $keyed === null) {
                            $differentOperationExists = AiProviderOperation::query()
                                ->where('workspace_id', $workspace->id)
                                ->where('budget_run_id', $draft->ai_budget_run_id)
                                ->exists();
                            if ($differentOperationExists) {
                                $this->reviewLocked($draft, 'draft_operation_missing', 'The arm budget contains different provider work; this draft cannot be safely repeated.');

                                continue;
                            }
                        }

                        // State and reservation commit together. Setting the state
                        // first makes the intended provider boundary explicit while
                        // the enclosing transaction eliminates a half-written gap.
                        $draft->forceFill([
                            'state' => ObjectiveRunArmDraftState::AwaitingProvider,
                            'started_at' => $draft->started_at ?? now(),
                            'finished_at' => null,
                        ])->save();
                        $operation = $this->provider->reserveGenerationForPolicy(
                            $workspace,
                            $actor,
                            AiTask::MessageDrafting,
                            $prompt['content'],
                            $key,
                            (string) $draft->model_id,
                            (int) $draft->task_policy_version,
                            (string) $draft->ai_budget_run_id,
                        );
                        if ((string) $operation->budget_run_id !== (string) $draft->ai_budget_run_id
                            || (string) $operation->budget_envelope_id !== (string) $lockedRun->ai_budget_envelope_id
                            || $operation->task !== AiTask::MessageDrafting
                            || ! hash_equals((string) $draft->model_id, (string) $operation->model_or_endpoint)
                            || (int) $operation->policy_version !== (int) $draft->task_policy_version) {
                            throw new RuntimeException('The reserved provider operation does not belong to this Objective arm budget.');
                        }
                        $draft->forceFill(['ai_provider_operation_id' => $operation->id])->save();
                    }

                    return true;
                });
        } catch (AiTaskPolicyMismatch) {
            // The task policy can change after the advisory comparison but
            // before the budget gateway acquires its authoritative lock. The
            // batch transaction has rolled back every hold at this point, so
            // convert that race into the same safe review outcome.
            return $this->reviewChangedPolicyUnderFence($run, $phase, $dispatch, $expectedFence);
        }
    }

    private function draftArm(
        Workspace $workspace,
        User $actor,
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        ObjectiveRunArmDraft $draft,
        int $expectedFence,
    ): void {
        $fresh = ObjectiveRunArmDraft::query()->where('workspace_id', $workspace->id)
            ->where('objective_run_id', $run->id)->whereKey($draft->id)->firstOrFail();
        if (in_array($fresh->state, [
            ObjectiveRunArmDraftState::Succeeded,
            ObjectiveRunArmDraftState::Abstained,
            ObjectiveRunArmDraftState::NeedsReview,
            ObjectiveRunArmDraftState::Failed,
            ObjectiveRunArmDraftState::Cancelled,
        ], true)) {
            return;
        }
        $arm = GtmExperimentArm::query()->where('workspace_id', $workspace->id)
            ->whereKey($fresh->gtm_experiment_arm_id)->firstOrFail();
        try {
            $prompt = $this->prompts->handle($arm);
        } catch (ValidationException) {
            $this->rejectDefinitelyNotSent(
                $run,
                $phase,
                $dispatch,
                $fresh,
                $expectedFence,
                'draft_evidence_unsafe',
                'The frozen Claim or Offer evidence is no longer safe for provider use.',
            );

            return;
        }
        if (! hash_equals((string) $fresh->input_hash, $prompt['input_hash'])) {
            $this->rejectDefinitelyNotSent(
                $run,
                $phase,
                $dispatch,
                $fresh,
                $expectedFence,
                'draft_input_changed',
                'The frozen drafting input no longer matches this run.',
            );

            return;
        }
        if (! $this->validatorVersionIsCurrent($run, [$fresh])) {
            $this->rejectChangedValidator($run, $phase, $dispatch, $fresh, $expectedFence);

            return;
        }
        $key = $this->providerKey($run, $arm, $fresh);
        $existingOperation = AiProviderOperation::query()->where('workspace_id', $workspace->id)
            ->where('idempotency_key', $key)->first();
        if ($existingOperation === null) {
            $this->review(
                $run,
                $phase,
                $dispatch,
                $fresh,
                $expectedFence,
                'draft_operation_missing',
                'No durable provider reservation exists for this arm.',
            );

            return;
        }
        if (! hash_equals((string) $fresh->model_id, (string) $existingOperation->model_or_endpoint)
            || (int) $fresh->task_policy_version !== (int) $existingOperation->policy_version) {
            $this->rejectChangedPolicy(
                $run,
                $phase,
                $dispatch,
                $fresh,
                $expectedFence,
                'draft_operation_policy_mismatch',
                'The durable provider operation does not match this arm\'s frozen model policy.',
            );

            return;
        }
        if ($existingOperation->status !== AiOperationStatus::Reserved) {
            $this->resolveInterruptedDraft(
                $run,
                $phase,
                $dispatch,
                $fresh,
                $expectedFence,
                $existingOperation,
            );

            return;
        }
        if (! $this->draftPolicyIsCurrent($workspace, $fresh)) {
            $this->rejectChangedPolicy($run, $phase, $dispatch, $fresh, $expectedFence);

            return;
        }
        if (! $this->ownsDraftFence($run, $phase, $dispatch, $expectedFence)) {
            return;
        }

        try {
            $result = $this->provider->generateForPolicy(
                $workspace,
                $actor,
                AiTask::MessageDrafting,
                $prompt['content'],
                $key,
                (string) $fresh->model_id,
                (int) $fresh->task_policy_version,
                (string) $fresh->ai_budget_run_id,
            );
            if (! $this->validatorVersionIsCurrent($run, [$fresh])) {
                $this->rejectChangedValidator($run, $phase, $dispatch, $fresh, $expectedFence);

                return;
            }
            $operation = AiProviderOperation::query()->where('workspace_id', $workspace->id)
                ->where('idempotency_key', $key)->firstOrFail();
            $strategyContract = $prompt['contract']['strategy'] ?? null;
            if (! is_array($strategyContract)) {
                throw new DraftMessageValidationException('The frozen outbound Strategy contract is missing.');
            }
            $strategyProjection = $this->templates->assertStrategyContract($strategyContract);
            $validated = $this->validator->handle(
                $result->content,
                $prompt['contract'],
                (int) $strategyProjection['maximum_words'],
            );
            $output = [
                'template_version' => DeterministicMessageTemplate::VERSION,
                'strategy_projection' => $strategyProjection,
                'strategy_projection_hash' => (string) $strategyContract['projection_hash'],
                'message_pattern' => $validated->messagePattern,
                'evidence_choice' => $validated->evidenceChoice,
                'cta_kind' => $validated->ctaKind,
                'abstain' => $validated->abstain,
                'abstention_reasons' => $validated->abstentionReasons,
            ];
            $templateHash = $validated->abstain ? null : CanonicalObjectivePayload::hash([
                'template_version' => DeterministicMessageTemplate::VERSION,
                'strategy_projection' => $strategyProjection,
                'strategy_projection_hash' => (string) $strategyContract['projection_hash'],
                'message_pattern' => $validated->messagePattern,
                'evidence_choice' => $validated->evidenceChoice,
                'offer_contract_hash' => (string) $prompt['contract']['offer']['contract_hash'],
            ]);
            $choice = null;
            $evidenceChoices = $prompt['contract']['evidence_choices'] ?? null;
            if (is_array($evidenceChoices)) {
                foreach ($evidenceChoices as $candidate) {
                    if (is_array($candidate)
                        && hash_equals((string) ($candidate['choice_id'] ?? ''), $validated->evidenceChoice)) {
                        $choice = $candidate;
                        break;
                    }
                }
            }
            if (! $validated->abstain && ! is_array($choice)) {
                throw new DraftMessageValidationException('The selected evidence choice is missing from the frozen arm contract.');
            }
            $this->persistValidatedDraft(
                $run,
                $phase,
                $dispatch,
                $fresh,
                $operation,
                $validated->abstain
                    ? ObjectiveRunArmDraftState::Abstained
                    : ObjectiveRunArmDraftState::Succeeded,
                null,
                null,
                [
                    'template_version' => DeterministicMessageTemplate::VERSION,
                    'strategy_projection' => $strategyProjection,
                    'strategy_projection_hash' => (string) $strategyContract['projection_hash'],
                    'message_pattern' => $validated->messagePattern,
                    'evidence_choice' => $validated->evidenceChoice,
                    'choice_coverage_hash' => is_array($choice) ? (string) ($choice['coverage_hash'] ?? '') : null,
                    'offer_version_id' => (string) $prompt['contract']['offer']['offer_version_id'],
                    'offer_content_hash' => (string) $prompt['contract']['offer']['content_hash'],
                    'offer_contract_hash' => (string) $prompt['contract']['offer']['contract_hash'],
                    'abstention_reasons' => $validated->abstentionReasons,
                ],
                [
                    'kind' => $validated->ctaKind,
                    'strategy_action' => (string) $strategyProjection['cta_kind'],
                    'text' => (string) $strategyProjection['cta_text'],
                    'strategy_projection' => $strategyProjection,
                    'strategy_projection_hash' => (string) $strategyContract['projection_hash'],
                    'fulfilment_mode' => (string) $prompt['contract']['offer']['fulfilment_mode'],
                    'destination_url' => (string) $prompt['contract']['offer']['destination_url'],
                    'response_route' => (string) $prompt['contract']['offer']['response_route'],
                    'offer_contract_hash' => (string) $prompt['contract']['offer']['contract_hash'],
                ],
                CanonicalObjectivePayload::hash($output),
                $templateHash,
                $expectedFence,
            );
            $this->budget->closeRun($fresh->budgetRun()->firstOrFail());
        } catch (AiTaskPolicyMismatch) {
            $this->rejectChangedPolicy($run, $phase, $dispatch, $fresh, $expectedFence);
        } catch (DraftMessageValidationException $exception) {
            $operation = AiProviderOperation::query()->where('workspace_id', $workspace->id)
                ->where('idempotency_key', $key)->first();
            $this->transitionAfterProvider(
                $run,
                $phase,
                $dispatch,
                $fresh,
                $expectedFence,
                $operation,
                ObjectiveRunArmDraftState::NeedsReview,
                'output_invalid',
                mb_substr($exception->getMessage(), 0, 500),
                true,
            );
            if ($operation?->status->terminal()) {
                $this->budget->closeRun($fresh->budgetRun()->firstOrFail());
            }
        } catch (SafeProviderException $exception) {
            $operation = AiProviderOperation::query()->where('workspace_id', $workspace->id)
                ->where('idempotency_key', $key)->first();
            $this->transitionAfterProvider(
                $run,
                $phase,
                $dispatch,
                $fresh,
                $expectedFence,
                $operation,
                $exception->ambiguous
                    ? ObjectiveRunArmDraftState::AwaitingProvider
                    : ($exception->safeCode === 'paid_result_lost'
                        ? ObjectiveRunArmDraftState::NeedsReview
                        : ObjectiveRunArmDraftState::Failed),
                $exception->safeCode,
                mb_substr($exception->getMessage(), 0, 500),
                ! $exception->ambiguous,
            );
            if (! $exception->ambiguous && $operation?->status->terminal()) {
                $this->budget->closeRun($fresh->budgetRun()->firstOrFail());
            }
        }
    }

    /**
     * @param  array<string, mixed>  $claimManifest
     * @param  array<string, mixed>  $ctaManifest
     */
    private function persistValidatedDraft(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        ObjectiveRunArmDraft $draft,
        AiProviderOperation $operation,
        ObjectiveRunArmDraftState $state,
        ?string $subject,
        ?string $body,
        array $claimManifest,
        array $ctaManifest,
        string $outputHash,
        ?string $templateHash,
        int $expectedFence,
    ): bool {
        return $this->withOwnedDraftingContext(
            $run,
            $phase,
            $dispatch,
            $expectedFence,
            $draft,
            function (ObjectiveRun $lockedRun, ?ObjectiveRunArmDraft $lockedDraft) use ($operation, $state, $subject, $body, $claimManifest, $ctaManifest, $outputHash, $templateHash): bool {
                if (! $lockedDraft instanceof ObjectiveRunArmDraft
                    || $lockedDraft->state !== ObjectiveRunArmDraftState::AwaitingProvider
                    || ($lockedDraft->ai_provider_operation_id !== null
                        && (string) $lockedDraft->ai_provider_operation_id !== (string) $operation->id)
                    || (string) $operation->workspace_id !== (string) $lockedRun->workspace_id
                    || (string) $operation->budget_run_id !== (string) $lockedDraft->ai_budget_run_id) {
                    return false;
                }
                $lockedDraft->forceFill([
                    'ai_provider_operation_id' => $operation->id,
                    'state' => $state,
                    'subject_template' => $subject,
                    'body_template' => $body,
                    'claim_manifest' => $claimManifest,
                    'cta_manifest' => $ctaManifest,
                    'output_hash' => $outputHash,
                    'template_hash' => $templateHash,
                    'safe_error_code' => null,
                    'safe_error_detail' => null,
                    'finished_at' => now(),
                ])->save();

                return true;
            },
        );
    }

    private function transitionAfterProvider(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        ObjectiveRunArmDraft $draft,
        int $expectedFence,
        ?AiProviderOperation $operation,
        ObjectiveRunArmDraftState $state,
        string $code,
        string $detail,
        bool $finished,
    ): bool {
        return $this->withOwnedDraftingContext(
            $run,
            $phase,
            $dispatch,
            $expectedFence,
            $draft,
            function (ObjectiveRun $lockedRun, ?ObjectiveRunArmDraft $lockedDraft) use ($operation, $state, $code, $detail, $finished): bool {
                if (! $lockedDraft instanceof ObjectiveRunArmDraft
                    || $lockedDraft->state !== ObjectiveRunArmDraftState::AwaitingProvider
                    || ($operation !== null
                        && ((int) $operation->workspace_id !== (int) $lockedRun->workspace_id
                            || (string) $operation->budget_run_id !== (string) $lockedDraft->ai_budget_run_id))
                    || ($lockedDraft->ai_provider_operation_id !== null
                        && $operation !== null
                        && (string) $lockedDraft->ai_provider_operation_id !== (string) $operation->id)) {
                    return false;
                }
                $lockedDraft->forceFill([
                    'ai_provider_operation_id' => $operation->id ?? $lockedDraft->ai_provider_operation_id,
                    'state' => $state,
                    'safe_error_code' => $code,
                    'safe_error_detail' => $detail,
                    'finished_at' => $finished ? now() : null,
                ])->save();

                return true;
            },
        );
    }

    private function ownsDraftFence(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        int $expectedFence,
    ): bool {
        return $this->withOwnedDraftingContext(
            $run,
            $phase,
            $dispatch,
            $expectedFence,
            null,
            static fn (ObjectiveRun $_lockedRun, ?ObjectiveRunArmDraft $_lockedDraft): bool => true,
        );
    }

    private function resolveInterruptedDraft(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        ObjectiveRunArmDraft $draft,
        int $expectedFence,
        ?AiProviderOperation $operation,
    ): bool {
        return $this->withOwnedDraftingContext(
            $run,
            $phase,
            $dispatch,
            $expectedFence,
            $draft,
            fn (ObjectiveRun $_lockedRun, ?ObjectiveRunArmDraft $lockedDraft): bool => $lockedDraft instanceof ObjectiveRunArmDraft
                ? $this->resolveInterruptedDraftLocked($lockedDraft, $operation)
                : false,
        );
    }

    private function resolveInterruptedDraftLocked(
        ObjectiveRunArmDraft $draft,
        ?AiProviderOperation $operation,
    ): bool {
        if (in_array($draft->state, [
            ObjectiveRunArmDraftState::NeedsReview,
            ObjectiveRunArmDraftState::Failed,
            ObjectiveRunArmDraftState::Cancelled,
        ], true)) {
            return false;
        }
        if ($operation === null) {
            return $this->reviewLocked($draft, 'draft_operation_missing', 'The provider operation boundary is missing; this draft will not be repeated.');
        }
        if ((int) $operation->workspace_id !== (int) $draft->workspace_id
            || (string) $operation->budget_run_id !== (string) $draft->ai_budget_run_id
            || ($draft->ai_provider_operation_id !== null
                && (string) $draft->ai_provider_operation_id !== (string) $operation->id)) {
            return $this->reviewLocked($draft, 'draft_operation_mismatch', 'The provider operation does not belong to this frozen arm.');
        }
        $draft->forceFill(['ai_provider_operation_id' => $operation->id]);
        if (in_array($operation->status, [
            AiOperationStatus::Executing,
            AiOperationStatus::Uncertain,
            AiOperationStatus::Reconciling,
        ], true)) {
            $draft->save();

            return true;
        }
        if (in_array($operation->status, [AiOperationStatus::Succeeded, AiOperationStatus::Reconciled, AiOperationStatus::ManualReview], true)) {
            return $this->reviewLocked($draft, 'paid_result_lost', 'The provider operation settled without a durable validated result; human review is required.');
        }
        $draft->forceFill([
            'state' => ObjectiveRunArmDraftState::Failed,
            'safe_error_code' => (string) ($operation->safe_error_code ?: 'provider_failed'),
            'safe_error_detail' => 'The provider operation ended without an executable result.',
            'finished_at' => now(),
        ])->save();

        return true;
    }

    private function rejectDefinitelyNotSent(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        ObjectiveRunArmDraft $draft,
        int $expectedFence,
        string $code,
        string $detail,
    ): bool {
        return $this->withOwnedDraftingContext(
            $run,
            $phase,
            $dispatch,
            $expectedFence,
            $draft,
            fn (ObjectiveRun $_lockedRun, ?ObjectiveRunArmDraft $lockedDraft): bool => $lockedDraft instanceof ObjectiveRunArmDraft
                ? $this->rejectDefinitelyNotSentLocked($lockedDraft, $code, $detail)
                : false,
        );
    }

    private function rejectDefinitelyNotSentLocked(
        ObjectiveRunArmDraft $draft,
        string $code,
        string $detail,
    ): bool {
        $operation = AiProviderOperation::query()
            ->where('workspace_id', $draft->workspace_id)
            ->where('budget_run_id', $draft->ai_budget_run_id)
            ->orderBy('id')
            ->first();
        if ($operation !== null && $operation->status !== AiOperationStatus::Reserved) {
            return $this->resolveInterruptedDraftLocked($draft, $operation);
        }
        if ($operation !== null) {
            $this->budget->releaseDefinitelyNotSent($operation, $code, $detail);
        }

        return $this->reviewLocked($draft, $code, $detail);
    }

    private function review(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        ObjectiveRunArmDraft $draft,
        int $expectedFence,
        string $code,
        string $detail,
    ): bool {
        return $this->withOwnedDraftingContext(
            $run,
            $phase,
            $dispatch,
            $expectedFence,
            $draft,
            fn (ObjectiveRun $_lockedRun, ?ObjectiveRunArmDraft $lockedDraft): bool => $lockedDraft instanceof ObjectiveRunArmDraft
                ? $this->reviewLocked($lockedDraft, $code, $detail)
                : false,
        );
    }

    private function reviewLocked(ObjectiveRunArmDraft $draft, string $code, string $detail): bool
    {
        if (in_array($draft->state, [
            ObjectiveRunArmDraftState::NeedsReview,
            ObjectiveRunArmDraftState::Failed,
            ObjectiveRunArmDraftState::Cancelled,
        ], true)) {
            return false;
        }
        $draft->forceFill([
            'state' => ObjectiveRunArmDraftState::NeedsReview,
            'safe_error_code' => $code,
            'safe_error_detail' => $detail,
            'finished_at' => now(),
        ])->save();

        return true;
    }

    private function draftPolicyIsCurrent(Workspace $workspace, ObjectiveRunArmDraft $draft): bool
    {
        $policy = AiTaskSetting::query()
            ->where('workspace_id', $workspace->id)
            ->where('task', AiTask::MessageDrafting->value)
            ->first();

        return $policy instanceof AiTaskSetting
            && hash_equals((string) $draft->model_id, (string) $policy->model_id)
            && (int) $draft->task_policy_version === (int) $policy->policy_version;
    }

    /**
     * @phpstan-impure The active configuration may change across a provider boundary.
     *
     * @param  array<int, ObjectiveRunArmDraft>  $drafts
     */
    private function validatorVersionIsCurrent(ObjectiveRun $run, array $drafts): bool
    {
        $active = (string) config('ai.tasks.message_drafting.validator_version');
        if ($active === ''
            || ! hash_equals(ValidateDraftedMessage::VERSION, $active)
            || ! hash_equals((string) $run->message_validator_version, $active)) {
            return false;
        }

        foreach ($drafts as $draft) {
            if (! hash_equals((string) $draft->validator_version, $active)) {
                return false;
            }
        }

        return true;
    }

    private function reviewChangedPolicyUnderFence(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        int $expectedFence,
    ): bool {
        return $this->withOwnedDraftingContext(
            $run,
            $phase,
            $dispatch,
            $expectedFence,
            null,
            function (ObjectiveRun $lockedRun): bool {
                $drafts = ObjectiveRunArmDraft::query()
                    ->where('workspace_id', $lockedRun->workspace_id)
                    ->where('objective_run_id', $lockedRun->id)
                    ->orderBy('gtm_experiment_arm_id')
                    ->lockForUpdate()
                    ->get();
                foreach ($drafts as $draft) {
                    if (! $draft->state->terminal()) {
                        $this->rejectChangedPolicyLocked($draft);
                    }
                }

                return true;
            },
        );
    }

    private function rejectChangedValidator(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        ObjectiveRunArmDraft $draft,
        int $expectedFence,
    ): bool {
        return $this->withOwnedDraftingContext(
            $run,
            $phase,
            $dispatch,
            $expectedFence,
            $draft,
            fn (ObjectiveRun $_lockedRun, ?ObjectiveRunArmDraft $lockedDraft): bool => $lockedDraft instanceof ObjectiveRunArmDraft
                ? $this->rejectChangedValidatorLocked($lockedDraft)
                : false,
        );
    }

    private function rejectChangedValidatorLocked(ObjectiveRunArmDraft $draft): bool
    {
        return $this->rejectDefinitelyNotSentLocked(
            $draft,
            'draft_validator_changed',
            'The active message validator changed after this Objective run was frozen.',
        );
    }

    private function rejectChangedPolicy(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        ObjectiveRunArmDraft $draft,
        int $expectedFence,
        string $code = 'draft_policy_changed',
        string $detail = 'The active message-drafting model policy changed after this run was frozen.',
    ): bool {
        return $this->withOwnedDraftingContext(
            $run,
            $phase,
            $dispatch,
            $expectedFence,
            $draft,
            fn (ObjectiveRun $_lockedRun, ?ObjectiveRunArmDraft $lockedDraft): bool => $lockedDraft instanceof ObjectiveRunArmDraft
                ? $this->rejectChangedPolicyLocked($lockedDraft, $code, $detail)
                : false,
        );
    }

    private function rejectChangedPolicyLocked(
        ObjectiveRunArmDraft $draft,
        string $code = 'draft_policy_changed',
        string $detail = 'The active message-drafting model policy changed after this run was frozen.',
    ): bool {
        return $this->rejectDefinitelyNotSentLocked($draft, $code, $detail);
    }

    /**
     * Execute one arm-draft mutation only while the caller still owns the exact
     * claimed run, phase and consumed dispatch. Every draft state transition in
     * this service passes through this transaction or its already-locked
     * callback during the all-arm reservation transaction.
     *
     * @param  callable(ObjectiveRun, ObjectiveRunArmDraft|null): bool  $callback
     */
    private function withOwnedDraftingContext(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ObjectiveRunDispatch $dispatch,
        int $expectedFence,
        ?ObjectiveRunArmDraft $draft,
        callable $callback,
    ): bool {
        $leaseOwner = $run->lease_owner;
        if (! is_string($leaseOwner) || $leaseOwner === ''
            || (int) $run->fencing_token !== $expectedFence
            || (int) $phase->workspace_id !== (int) $run->workspace_id
            || (string) $phase->objective_run_id !== (string) $run->id
            || $phase->phase !== ObjectiveRunPhaseName::DraftMessages
            || $phase->state !== ObjectiveRunPhaseState::Running
            || (int) $phase->fencing_token !== $expectedFence
            || ! hash_equals((string) $phase->lease_owner, $leaseOwner)
            || (int) $dispatch->workspace_id !== (int) $run->workspace_id
            || (string) $dispatch->objective_run_id !== (string) $run->id
            || $dispatch->phase !== ObjectiveRunPhaseName::DraftMessages
            || $dispatch->state !== ObjectiveRunDispatchState::Consumed
            || ! hash_equals((string) $dispatch->input_hash, (string) $phase->input_hash)) {
            return false;
        }

        return DB::transaction(function () use ($run, $phase, $dispatch, $expectedFence, $leaseOwner, $draft, $callback): bool {
            $now = now();
            $lockedRun = ObjectiveRun::query()
                ->where('workspace_id', $run->workspace_id)
                ->whereKey($run->id)
                ->where('state', ObjectiveRunState::Running->value)
                ->where('current_phase', ObjectiveRunPhaseName::DraftMessages->value)
                ->where('fencing_token', $expectedFence)
                ->where('lease_owner', $leaseOwner)
                ->where('lease_expires_at', '>', $now)
                ->lockForUpdate()
                ->first();
            if ($lockedRun === null) {
                return false;
            }
            $lockedPhase = ObjectiveRunPhase::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('objective_run_id', $run->id)
                ->whereKey($phase->id)
                ->where('phase', ObjectiveRunPhaseName::DraftMessages->value)
                ->where('state', ObjectiveRunPhaseState::Running->value)
                ->where('fencing_token', $expectedFence)
                ->where('lease_owner', $leaseOwner)
                ->where('lease_expires_at', '>', $now)
                ->lockForUpdate()
                ->first();
            if ($lockedPhase === null
                || ! hash_equals((string) $lockedPhase->input_hash, (string) $phase->input_hash)) {
                return false;
            }
            $lockedDispatch = ObjectiveRunDispatch::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('objective_run_id', $run->id)
                ->whereKey($dispatch->id)
                ->where('phase', ObjectiveRunPhaseName::DraftMessages->value)
                ->where('state', ObjectiveRunDispatchState::Consumed->value)
                ->where('input_hash', $lockedPhase->input_hash)
                ->whereNotNull('consumed_at')
                ->lockForUpdate()
                ->first();
            if ($lockedDispatch === null) {
                return false;
            }
            $lockedDraft = $draft === null
                ? null
                : ObjectiveRunArmDraft::query()
                    ->where('workspace_id', $run->workspace_id)
                    ->where('objective_run_id', $run->id)
                    ->whereKey($draft->id)
                    ->lockForUpdate()
                    ->first();
            if ($draft !== null && $lockedDraft === null) {
                return false;
            }

            return $callback($lockedRun, $lockedDraft);
        }, 3);
    }

    private function providerKey(ObjectiveRun $run, GtmExperimentArm $arm, ObjectiveRunArmDraft $draft): string
    {
        $versionedInput = hash('sha256', $draft->prompt_version.'|'.$draft->input_hash);

        return implode(':', ['s3', $run->id, 'draft', $arm->id, $versionedInput]);
    }

    private function syncCosts(ObjectiveRun $run): void
    {
        $envelope = AiBudgetEnvelope::query()->where('workspace_id', $run->workspace_id)
            ->whereKey($run->ai_budget_envelope_id)->firstOrFail();
        $run->forceFill([
            'reserved_cost_micros' => (int) $envelope->held_micros,
            'actual_cost_micros' => (int) $envelope->committed_micros,
        ])->save();
    }
}
