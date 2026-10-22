<?php

namespace App\Domain\Objectives\Services;

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

final class RejectExecutionPlan
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
        string $reason,
    ): ApprovalBatch {
        return DB::transaction(function () use ($plan, $actor, $planHash, $displayManifestHash, $actionFingerprints, $reason): ApprovalBatch {
            $workspaceId = $this->ownerWorkspaceId($plan, $actor);
            $plan = ExecutionPlan::query()->where('workspace_id', $workspaceId)
                ->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            /** @var Collection<int, ExecutionPlanAction> $actions */
            $actions = ExecutionPlanAction::query()->where('workspace_id', $workspaceId)
                ->where('execution_plan_id', $plan->id)->orderBy('id')->lockForUpdate()->get();
            $provided = array_map('strval', $actionFingerprints);
            sort($provided, SORT_STRING);
            if (count($provided) !== count(array_unique($provided))
                || ! hash_equals((string) $plan->plan_hash, $planHash)
                || ! hash_equals($this->manifest->hash($plan, $actions), $displayManifestHash)
                || $provided !== $this->manifest->actionFingerprints($plan, $actions)) {
                throw new ApprovalConflict('approval_input_changed');
            }
            $reason = trim($reason);
            $inputHash = CanonicalObjectivePayload::hash([
                'version' => 'approval_decision_input_v1',
                'decision' => 'rejected',
                'actor_user_id' => (int) $actor->id,
                'plan_hash' => $planHash,
                'display_manifest_hash' => $displayManifestHash,
                'action_fingerprints' => $provided,
                'reason' => mb_substr($reason, 0, 500),
            ]);
            $existing = ApprovalBatch::query()->where('workspace_id', $workspaceId)
                ->where('execution_plan_id', $plan->id)->lockForUpdate()->first();
            if ($existing instanceof ApprovalBatch) {
                if ($existing->state === ApprovalBatchState::Rejected
                    && (int) $existing->decision_by_user_id === (int) $actor->id
                    && hash_equals((string) $existing->decision_input_hash, $inputHash)) {
                    return $existing->fresh(['grants.events', 'events']) ?? $existing;
                }
                throw new ApprovalConflict(
                    'approval_already_decided',
                    message: 'This exact action batch already has a different decision.',
                );
            }

            // A rejection is safe even when readiness is blocked, but its exact
            // policy result is still retained beside every immutable grant.
            $decision = $this->checkpoint->forPlan($plan, PolicyEvaluationStage::Approval, false);
            $now = now();
            $batch = ApprovalBatch::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'execution_plan_id' => $plan->id,
                'plan_hash' => $plan->plan_hash,
                'state' => ApprovalBatchState::Rejected,
                'action_count' => $actions->count(),
                'display_manifest_hash' => $displayManifestHash,
                'decision_input_hash' => $inputHash,
                'decision_by_user_id' => $actor->id,
                'decided_at' => $now,
                'expires_at' => null,
                'reason_code' => 'owner_rejected',
                'reason_detail' => mb_substr($reason, 0, 500),
            ]);
            $evaluations = $decision->evaluations->keyBy('execution_plan_action_id');
            foreach ($actions as $action) {
                $evaluation = $evaluations->get((string) $action->id);
                if (! $evaluation instanceof PolicyEvaluation) {
                    throw new ApprovalConflict('policy_evaluation_missing');
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
                    'decision' => ApprovalGrantDecision::Rejected,
                    'decision_by_user_id' => $actor->id,
                    'approved_at' => null,
                    'rejected_at' => $now,
                    'expires_at' => null,
                    'created_at' => $now,
                ]);
                ApprovalEvent::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'approval_batch_id' => $batch->id,
                    'approval_grant_id' => $grant->id,
                    'execution_plan_action_id' => $action->id,
                    'event' => ApprovalEventType::Rejected,
                    'prior_state' => ApprovalBatchState::Pending->value,
                    'projected_state' => ApprovalBatchState::Rejected->value,
                    'reason_code' => 'owner_rejected',
                    'dependency_hash' => $decision->dependencyHash,
                    'event_input_hash' => $inputHash,
                    'actor_user_id' => $actor->id,
                    'occurred_at' => $now,
                    'created_at' => $now,
                ]);
            }
            DB::table('execution_plan_actions')->where('workspace_id', $workspaceId)
                ->where('execution_plan_id', $plan->id)
                ->update(['state' => ExecutionPlanActionState::Rejected->value, 'updated_at' => $now]);
            DB::table('execution_plans')->where('workspace_id', $workspaceId)->where('id', $plan->id)
                ->update(['status' => ExecutionPlanStatus::Rejected->value, 'updated_at' => $now]);

            return $batch->fresh(['grants.events', 'events']) ?? $batch;
        }, 3);
    }

    private function ownerWorkspaceId(ExecutionPlan $plan, User $actor): int
    {
        $workspaceId = (int) $actor->currentWorkspace()->getKey();
        if ($workspaceId < 1 || (int) $plan->workspace_id !== $workspaceId
            || ! WorkspaceMembership::query()->where('workspace_id', $workspaceId)
                ->where('user_id', $actor->id)->where('role', WorkspaceMembership::ROLE_OWNER)->exists()) {
            throw new ApprovalConflict('approval_owner_required');
        }

        return $workspaceId;
    }
}
