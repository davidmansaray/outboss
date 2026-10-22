<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\DTOs\ApprovalCheckpointDecision;
use App\Domain\Objectives\DTOs\ExactPlanValidation;
use App\Domain\Objectives\Enums\ApprovalBatchState;
use App\Domain\Objectives\Enums\ApprovalEventType;
use App\Domain\Objectives\Enums\ApprovalGrantDecision;
use App\Domain\Objectives\Enums\PolicyEvaluationResult;
use App\Domain\Objectives\Enums\PolicyEvaluationStage;
use App\Domain\Objectives\Exceptions\ApprovalConflict;
use App\Domain\Objectives\Support\ApprovalManifest;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\ApprovalBatch;
use App\Models\ApprovalEvent;
use App\Models\ApprovalGrant;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\PolicyEvaluation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Public fail-closed checkpoint for compiler and mail execution workers.
 *
 * Mail seams should call forAction() with the current stage immediately before
 * launch, schedule, lease, or provider work. The decision is authoritative
 * only inside the callback/transaction that performs that guarded mutation.
 */
final class GovernedExecutionCheckpoint
{
    public const EVALUATOR_VERSION = 'governed_execution_checkpoint_v1';

    private const INVALIDATING_BLOCKERS = [
        'action_set_changed',
        'execution_scope_revoked',
        'execution_scope_changed',
        'execution_scope_expired',
        'execution_scope_window_exceeded',
        'legacy_authority_changed',
        'action_hash_mismatch',
        'plan_hash_mismatch',
        'plan_superseded',
        'plan_state_invalid',
        'run_changed',
        'run_input_changed',
        'objective_version_changed',
        'policy_changed',
        'policy_cap_exceeded',
        'audience_changed',
        'experiment_changed',
        'assignment_changed',
        'recipient_changed',
        'strategy_changed',
        'reason_to_contact_changed',
        'evidence_changed',
        'offer_changed',
        'offer_content_changed',
        'conversion_changed',
        'sender_identity_changed',
        'schedule_changed',
        'policy_input_changed',
        'content_changed',
    ];

    public function __construct(
        private readonly ExactExecutionPlanValidator $validator,
        private readonly ApprovalManifest $manifest,
    ) {}

    public function forPlan(
        ExecutionPlan $plan,
        PolicyEvaluationStage $stage,
        bool $requireApproval = true,
    ): ApprovalCheckpointDecision {
        return $this->evaluate($plan, $stage, $requireApproval, null);
    }

    public function forAction(
        ExecutionPlanAction $action,
        PolicyEvaluationStage $stage,
    ): ApprovalCheckpointDecision {
        $plan = ExecutionPlan::query()
            ->where('workspace_id', $action->workspace_id)
            ->whereKey($action->execution_plan_id)
            ->firstOrFail();

        return $this->evaluate($plan, $stage, true, (string) $action->id);
    }

    private function evaluate(
        ExecutionPlan $plan,
        PolicyEvaluationStage $stage,
        bool $requireApproval,
        ?string $onlyActionId,
    ): ApprovalCheckpointDecision {
        return DB::transaction(function () use ($plan, $stage, $requireApproval, $onlyActionId): ApprovalCheckpointDecision {
            $validation = $this->validator->handle($plan, true);
            $blockers = $validation->blockers;
            if ($blockers === []) {
                try {
                    app(AssertObjectiveSharedCapacity::class)->handle($plan, $validation->actions);
                } catch (ApprovalConflict $error) {
                    $this->block($blockers, $error->reasonCode, $error->getMessage());
                }
            }
            if (in_array($stage, [PolicyEvaluationStage::Schedule, PolicyEvaluationStage::Lease, PolicyEvaluationStage::PreSend], true)) {
                foreach ($validation->actions as $action) {
                    if (now()->lessThan($action->scheduled_not_before)) {
                        $this->block($blockers, 'schedule_not_open', 'The approved sending window has not opened yet.', (string) $action->id);
                    }
                }
            }
            if ($stage !== PolicyEvaluationStage::Approval && ! config('outboss.campaign_sending_enabled')) {
                $this->block($blockers, 'global_sending_disabled', 'The global campaign sending safety brake is active.');
            }

            $batch = null;
            if ($requireApproval) {
                $batch = ApprovalBatch::query()
                    ->where('workspace_id', $plan->workspace_id)
                    ->where('execution_plan_id', $plan->id)
                    ->lockForUpdate()
                    ->first();
                $this->assertGrantSet($plan, $validation, $batch, $blockers);
            }

            /** @var Collection<int, PolicyEvaluation> $evaluations */
            $evaluations = new Collection;
            foreach ($validation->actions as $action) {
                if ($onlyActionId !== null && ! hash_equals($onlyActionId, (string) $action->id)) {
                    continue;
                }
                $actionBlockers = array_values(array_filter(
                    $blockers,
                    static fn (array $blocker): bool => ! isset($blocker['action_id'])
                        || hash_equals((string) $blocker['action_id'], (string) $action->id),
                ));
                $evaluations->push(PolicyEvaluation::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $plan->workspace_id,
                    'outreach_policy_version_id' => $plan->outreach_policy_version_id,
                    'execution_plan_id' => $plan->id,
                    'execution_plan_action_id' => $action->id,
                    'stage' => $stage,
                    'result' => $actionBlockers === []
                        ? PolicyEvaluationResult::Allowed
                        : PolicyEvaluationResult::Blocked,
                    'input_hash' => $validation->actionInputHashes[(string) $action->id]
                        ?? CanonicalObjectivePayload::hash(['missing_action_input' => (string) $action->id]),
                    'dependency_hash' => $validation->dependencyHash,
                    'reason_codes' => array_values(array_unique(array_column($actionBlockers, 'code'))),
                    'safe_details' => $actionBlockers,
                    'evaluator_version' => self::EVALUATOR_VERSION,
                    'evaluated_at' => now(),
                    'valid_until' => $validation->validUntil,
                    'created_at' => now(),
                ]));
            }

            return new ApprovalCheckpointDecision(
                blockers: $blockers,
                warnings: $validation->warnings,
                actionInputHashes: $validation->actionInputHashes,
                dependencyHash: $validation->dependencyHash,
                policyVersionId: $validation->policyVersionId,
                validUntil: $validation->validUntil,
                evaluations: $evaluations,
                batch: $batch?->fresh(['grants.events', 'events']) ?? $batch,
            );
        }, 3);
    }

    /** @param list<array{code:string,message:string,action_id?:string}> $blockers */
    private function assertGrantSet(
        ExecutionPlan $plan,
        ExactPlanValidation $validation,
        ?ApprovalBatch $batch,
        array &$blockers,
    ): void {
        if (! $batch instanceof ApprovalBatch) {
            $this->block($blockers, 'approval_missing', 'No owner approval batch exists for this exact plan.');

            return;
        }
        if ($batch->state !== ApprovalBatchState::Approved) {
            $this->block(
                $blockers,
                match ($batch->state) {
                    ApprovalBatchState::Expired => 'approval_expired',
                    ApprovalBatchState::Revoked => 'approval_revoked',
                    ApprovalBatchState::Invalidated => 'approval_invalidated',
                    ApprovalBatchState::Rejected => 'approval_rejected',
                    default => 'approval_missing',
                },
                'The owner approval is not currently executable.',
            );

            return;
        }

        if ($batch->expires_at === null || ! now()->lessThan($batch->expires_at)) {
            $this->terminalize($batch, $validation, ApprovalEventType::Expired, 'approval_expired');
            $this->block($blockers, 'approval_expired', 'The exact owner approval expired.');

            return;
        }
        if (! hash_equals((string) $batch->plan_hash, (string) $plan->plan_hash)
            || ! hash_equals((string) $batch->display_manifest_hash, $this->manifest->hash($plan, $validation->actions))
            || (int) $batch->action_count !== $validation->actions->count()) {
            $this->terminalize($batch, $validation, ApprovalEventType::Invalidated, 'approval_input_changed');
            $this->block($blockers, 'approval_invalidated', 'The approved plan display or fixed action set changed.');

            return;
        }

        $invalidating = collect($validation->blockers)->first(
            fn (array $blocker): bool => in_array($blocker['code'], self::INVALIDATING_BLOCKERS, true),
        );
        if (is_array($invalidating)) {
            $this->terminalize(
                $batch,
                $validation,
                ApprovalEventType::Invalidated,
                (string) $invalidating['code'],
            );
            $this->block($blockers, 'approval_invalidated', 'A frozen dependency changed after approval.');

            return;
        }

        /** @var Collection<int, ApprovalGrant> $grants */
        $grants = ApprovalGrant::query()
            ->where('workspace_id', $plan->workspace_id)
            ->where('approval_batch_id', $batch->id)
            ->orderBy('execution_plan_action_id')
            ->lockForUpdate()
            ->get();
        if ($grants->count() !== $validation->actions->count()) {
            $this->terminalize($batch, $validation, ApprovalEventType::Invalidated, 'approval_grant_set_changed');
            $this->block($blockers, 'approval_invalidated', 'The immutable per-action grant set is incomplete.');

            return;
        }
        $grantsByAction = $grants->keyBy('execution_plan_action_id');
        foreach ($validation->actions as $action) {
            $grant = $grantsByAction->get((string) $action->id);
            if (! $grant instanceof ApprovalGrant
                || $grant->decision !== ApprovalGrantDecision::Approved
                || ! hash_equals((string) $grant->plan_hash, (string) $plan->plan_hash)
                || ! hash_equals((string) $grant->action_fingerprint, (string) $action->action_fingerprint)
                || ! hash_equals((string) $grant->outreach_policy_version_id, (string) $plan->outreach_policy_version_id)
                || ! hash_equals(
                    (string) $grant->policy_input_hash,
                    (string) ($validation->actionInputHashes[(string) $action->id] ?? ''),
                )
                || $grant->expires_at === null
                || ! now()->lessThan($grant->expires_at)) {
                $this->terminalize($batch, $validation, ApprovalEventType::Invalidated, 'approval_grant_mismatch');
                $this->block(
                    $blockers,
                    'approval_invalidated',
                    'An immutable per-action grant no longer matches the current exact action.',
                    (string) $action->id,
                );

                return;
            }
        }
    }

    private function terminalize(
        ApprovalBatch $batch,
        ExactPlanValidation $validation,
        ApprovalEventType $event,
        string $reasonCode,
    ): void {
        if ($batch->state !== ApprovalBatchState::Approved) {
            return;
        }
        $projected = $event === ApprovalEventType::Expired
            ? ApprovalBatchState::Expired
            : ApprovalBatchState::Invalidated;
        $inputHash = CanonicalObjectivePayload::hash([
            'event' => $event->value,
            'batch_id' => (string) $batch->id,
            'dependency_hash' => $validation->dependencyHash,
            'reason_code' => $reasonCode,
        ]);
        $grants = ApprovalGrant::query()
            ->where('workspace_id', $batch->workspace_id)
            ->where('approval_batch_id', $batch->id)
            ->lockForUpdate()
            ->get();
        foreach ($grants as $grant) {
            ApprovalEvent::query()->firstOrCreate([
                'workspace_id' => $batch->workspace_id,
                'approval_grant_id' => $grant->id,
                'event' => $event->value,
            ], [
                'id' => (string) Str::uuid(),
                'approval_batch_id' => $batch->id,
                'execution_plan_action_id' => $grant->execution_plan_action_id,
                'prior_state' => ApprovalBatchState::Approved->value,
                'projected_state' => $projected->value,
                'reason_code' => $reasonCode,
                'dependency_hash' => $validation->dependencyHash,
                'event_input_hash' => $inputHash,
                'actor_user_id' => null,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
        }
        $batch->forceFill([
            'state' => $projected,
            'reason_code' => $reasonCode,
            'reason_detail' => $event === ApprovalEventType::Expired
                ? 'The exact approval lifetime elapsed.'
                : 'A frozen approval dependency changed.',
        ])->save();
    }

    /** @param list<array{code:string,message:string,action_id?:string}> $items */
    private function block(array &$items, string $code, string $message, ?string $actionId = null): void
    {
        if (collect($items)->contains(fn (array $item): bool => $item['code'] === $code
            && ($item['action_id'] ?? null) === $actionId)) {
            return;
        }
        $item = compact('code', 'message');
        if ($actionId !== null) {
            $item['action_id'] = $actionId;
        }
        $items[] = $item;
    }
}
