<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\DTOs\ApprovalCheckpointDecision;
use App\Domain\Objectives\Enums\ApprovalBatchState;
use App\Domain\Objectives\Enums\ApprovalEventType;
use App\Domain\Objectives\Enums\ApprovalGrantDecision;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Domain\Objectives\Enums\ExecutionPlanStatus;
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
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Owner decision over one fixed, fully displayed exact action batch. */
final class ApproveExecutionPlan
{
    public function __construct(
        private readonly GovernedExecutionCheckpoint $checkpoint,
        private readonly ApprovalManifest $manifest,
    ) {}

    /** @param list<string> $actionFingerprints */
    public function handle(
        ExecutionPlan $plan,
        User $actor,
        string $planHash,
        string $displayManifestHash,
        array $actionFingerprints,
        ?string $reason = null,
    ): ApprovalBatch {
        $blocked = null;
        $result = DB::transaction(function () use (
            $plan,
            $actor,
            $planHash,
            $displayManifestHash,
            $actionFingerprints,
            $reason,
            &$blocked,
        ): ?ApprovalBatch {
            $workspaceId = $this->ownerWorkspaceId($plan, $actor);
            $plan = ExecutionPlan::query()->where('workspace_id', $workspaceId)
                ->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            /** @var Collection<int, ExecutionPlanAction> $actions */
            $actions = ExecutionPlanAction::query()->where('workspace_id', $workspaceId)
                ->where('execution_plan_id', $plan->id)->orderBy('id')->lockForUpdate()->get();
            $this->assertEcho($plan, $actions, $planHash, $displayManifestHash, $actionFingerprints);
            $decisionInputHash = $this->decisionInputHash(
                'approved',
                $actor,
                $planHash,
                $displayManifestHash,
                $actionFingerprints,
                $reason,
            );
            $existing = ApprovalBatch::query()->where('workspace_id', $workspaceId)
                ->where('execution_plan_id', $plan->id)->lockForUpdate()->first();
            if ($existing instanceof ApprovalBatch) {
                if ($existing->state === ApprovalBatchState::Approved
                    && (int) $existing->decision_by_user_id === (int) $actor->id
                    && hash_equals((string) $existing->decision_input_hash, $decisionInputHash)) {
                    return $existing->fresh(['grants.events', 'events']) ?? $existing;
                }

                throw new ApprovalConflict(
                    'approval_already_decided',
                    message: 'This exact action batch already has a different decision.',
                );
            }

            $decision = $this->checkpoint->forPlan($plan, PolicyEvaluationStage::Approval, false);
            if (! $decision->allowed()) {
                $blocked = $decision;

                return null;
            }
            if ($decision->validUntil === null || ! now()->lessThan($decision->validUntil)) {
                throw new ApprovalConflict('approval_window_closed', message: 'No safe approval lifetime remains.');
            }

            app(AssertObjectiveSharedCapacity::class)->handle($plan, $actions);

            $now = now();
            $batch = ApprovalBatch::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'execution_plan_id' => $plan->id,
                'plan_hash' => $plan->plan_hash,
                'state' => ApprovalBatchState::Approved,
                'action_count' => $actions->count(),
                'display_manifest_hash' => $displayManifestHash,
                'decision_input_hash' => $decisionInputHash,
                'decision_by_user_id' => $actor->id,
                'decided_at' => $now,
                'expires_at' => $decision->validUntil,
                'reason_code' => 'owner_approved',
                'reason_detail' => $this->reason($reason),
            ]);
            $evaluations = $decision->evaluations->keyBy('execution_plan_action_id');
            foreach ($actions as $action) {
                $evaluation = $evaluations->get((string) $action->id);
                if (! $evaluation instanceof PolicyEvaluation) {
                    throw new ApprovalConflict('policy_evaluation_missing', message: 'An action policy evaluation was not recorded.');
                }
                $grant = ApprovalGrant::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'approval_batch_id' => $batch->id,
                    'execution_plan_id' => $plan->id,
                    'execution_plan_action_id' => $action->id,
                    'policy_evaluation_id' => $evaluation->id,
                    'plan_hash' => $plan->plan_hash,
                    'action_fingerprint' => $action->action_fingerprint,
                    'outreach_policy_version_id' => $plan->outreach_policy_version_id,
                    'policy_input_hash' => $decision->actionInputHashes[(string) $action->id],
                    'decision' => ApprovalGrantDecision::Approved,
                    'decision_by_user_id' => $actor->id,
                    'approved_at' => $now,
                    'rejected_at' => null,
                    'expires_at' => $decision->validUntil,
                    'created_at' => $now,
                ]);
                ApprovalEvent::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'approval_batch_id' => $batch->id,
                    'approval_grant_id' => $grant->id,
                    'execution_plan_action_id' => $action->id,
                    'event' => ApprovalEventType::Approved,
                    'prior_state' => ApprovalBatchState::Pending->value,
                    'projected_state' => ApprovalBatchState::Approved->value,
                    'reason_code' => 'owner_approved',
                    'dependency_hash' => $decision->dependencyHash,
                    'event_input_hash' => $decisionInputHash,
                    'actor_user_id' => $actor->id,
                    'occurred_at' => $now,
                    'created_at' => $now,
                ]);
            }
            DB::table('execution_plan_actions')->where('workspace_id', $workspaceId)
                ->where('execution_plan_id', $plan->id)
                ->update(['state' => ExecutionPlanActionState::Approved->value, 'updated_at' => $now]);
            DB::table('execution_plans')->where('workspace_id', $workspaceId)->where('id', $plan->id)
                ->update(['status' => ExecutionPlanStatus::Approved->value, 'updated_at' => $now]);

            return $batch->fresh(['grants.events', 'events']) ?? $batch;
        }, 3);

        if ($blocked instanceof ApprovalCheckpointDecision) {
            throw new ApprovalConflict(
                $blocked->primaryCode(),
                $blocked->blockers,
                'The plan failed exact policy or dependency revalidation.',
            );
        }
        if (! $result instanceof ApprovalBatch) {
            throw new ApprovalConflict('approval_failed');
        }

        return $result;
    }

    /** @param Collection<int, ExecutionPlanAction> $actions
     * @param  list<string>  $actionFingerprints
     */
    private function assertEcho(
        ExecutionPlan $plan,
        Collection $actions,
        string $planHash,
        string $displayManifestHash,
        array $actionFingerprints,
    ): void {
        $provided = array_map('strval', $actionFingerprints);
        sort($provided, SORT_STRING);
        $expected = $this->manifest->actionFingerprints($plan, $actions);
        if (count($provided) !== count(array_unique($provided))
            || ! hash_equals((string) $plan->plan_hash, $planHash)
            || ! hash_equals($this->manifest->hash($plan, $actions), $displayManifestHash)
            || $provided !== $expected) {
            throw new ApprovalConflict(
                'approval_input_changed',
                message: 'Refresh the plan: its hash, displayed manifest, or fixed action set changed.',
            );
        }
    }

    /** @param list<string> $actionFingerprints */
    private function decisionInputHash(
        string $decision,
        User $actor,
        string $planHash,
        string $displayManifestHash,
        array $actionFingerprints,
        ?string $reason,
    ): string {
        $fingerprints = array_map('strval', $actionFingerprints);
        sort($fingerprints, SORT_STRING);

        return CanonicalObjectivePayload::hash([
            'version' => 'approval_decision_input_v1',
            'decision' => $decision,
            'actor_user_id' => (int) $actor->id,
            'plan_hash' => $planHash,
            'display_manifest_hash' => $displayManifestHash,
            'action_fingerprints' => $fingerprints,
            'reason' => $this->reason($reason),
        ]);
    }

    private function ownerWorkspaceId(ExecutionPlan $plan, User $actor): int
    {
        $workspaceId = (int) $actor->currentWorkspace()->getKey();
        if ($workspaceId < 1 || (int) $plan->workspace_id !== $workspaceId
            || ! WorkspaceMembership::query()->where('workspace_id', $workspaceId)
                ->where('user_id', $actor->id)->where('role', WorkspaceMembership::ROLE_OWNER)->exists()) {
            throw new ApprovalConflict('approval_owner_required', message: 'Only the current workspace owner may approve execution.');
        }

        return $workspaceId;
    }

    private function reason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : mb_substr($reason, 0, 500);
    }
}
