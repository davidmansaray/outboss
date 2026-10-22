<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\ApprovalBatchState;
use App\Domain\Objectives\Enums\ApprovalEventType;
use App\Domain\Objectives\Enums\ExecutionPlanActionState;
use App\Domain\Objectives\Enums\ExecutionPlanStatus;
use App\Domain\Objectives\Exceptions\ApprovalConflict;
use App\Domain\Objectives\Support\ApprovalManifest;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\ApprovalBatch;
use App\Models\ApprovalEvent;
use App\Models\ApprovalGrant;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RevokeExecutionApproval
{
    public function __construct(private readonly ApprovalManifest $manifest) {}

    /** @param list<string> $actionFingerprints */
    public function handle(
        ApprovalBatch $batch,
        User $actor,
        string $planHash,
        string $displayManifestHash,
        array $actionFingerprints,
        string $reason,
    ): ApprovalBatch {
        return DB::transaction(function () use ($batch, $actor, $planHash, $displayManifestHash, $actionFingerprints, $reason): ApprovalBatch {
            $workspaceId = (int) $actor->currentWorkspace()->getKey();
            if ($workspaceId < 1 || (int) $batch->workspace_id !== $workspaceId
                || ! WorkspaceMembership::query()->where('workspace_id', $workspaceId)
                    ->where('user_id', $actor->id)->where('role', WorkspaceMembership::ROLE_OWNER)->exists()) {
                throw new ApprovalConflict('approval_owner_required');
            }
            $batch = ApprovalBatch::query()->where('workspace_id', $workspaceId)
                ->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $plan = ExecutionPlan::query()->where('workspace_id', $workspaceId)
                ->whereKey($batch->execution_plan_id)->lockForUpdate()->firstOrFail();
            /** @var Collection<int, ExecutionPlanAction> $actions */
            $actions = ExecutionPlanAction::query()->where('workspace_id', $workspaceId)
                ->where('execution_plan_id', $plan->id)->orderBy('id')->lockForUpdate()->get();
            $provided = array_map('strval', $actionFingerprints);
            sort($provided, SORT_STRING);
            if (count($provided) !== count(array_unique($provided))
                || ! hash_equals((string) $batch->plan_hash, $planHash)
                || ! hash_equals((string) $plan->plan_hash, $planHash)
                || ! hash_equals((string) $batch->display_manifest_hash, $displayManifestHash)
                || ! hash_equals($this->manifest->hash($plan, $actions), $displayManifestHash)
                || $provided !== $this->manifest->actionFingerprints($plan, $actions)) {
                throw new ApprovalConflict('approval_input_changed');
            }
            $reason = trim($reason);
            $inputHash = CanonicalObjectivePayload::hash([
                'version' => 'approval_revocation_input_v1',
                'actor_user_id' => (int) $actor->id,
                'batch_id' => (string) $batch->id,
                'plan_hash' => $planHash,
                'display_manifest_hash' => $displayManifestHash,
                'action_fingerprints' => $provided,
                'reason' => mb_substr($reason, 0, 500),
            ]);
            if ($batch->state === ApprovalBatchState::Revoked) {
                $events = ApprovalEvent::query()->where('workspace_id', $workspaceId)
                    ->where('approval_batch_id', $batch->id)
                    ->where('event', ApprovalEventType::Revoked->value)->get();
                if ($events->count() === $actions->count()
                    && $events->every(fn (ApprovalEvent $event): bool => (int) $event->actor_user_id === (int) $actor->id
                        && hash_equals((string) $event->event_input_hash, $inputHash))) {
                    return $batch->fresh(['grants.events', 'events']) ?? $batch;
                }
                throw new ApprovalConflict('approval_already_revoked');
            }
            if ($batch->state !== ApprovalBatchState::Approved
                || $batch->expires_at === null || ! now()->lessThan($batch->expires_at)) {
                throw new ApprovalConflict('approval_not_active');
            }

            $grants = ApprovalGrant::query()->where('workspace_id', $workspaceId)
                ->where('approval_batch_id', $batch->id)->orderBy('execution_plan_action_id')
                ->lockForUpdate()->get();
            if ($grants->count() !== $actions->count()) {
                throw new ApprovalConflict('approval_grant_set_changed');
            }
            $now = now();
            foreach ($grants as $grant) {
                ApprovalEvent::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'approval_batch_id' => $batch->id,
                    'approval_grant_id' => $grant->id,
                    'execution_plan_action_id' => $grant->execution_plan_action_id,
                    'event' => ApprovalEventType::Revoked,
                    'prior_state' => ApprovalBatchState::Approved->value,
                    'projected_state' => ApprovalBatchState::Revoked->value,
                    'reason_code' => 'owner_revoked',
                    'dependency_hash' => $grant->events()->oldest('occurred_at')->value('dependency_hash')
                        ?? str_repeat('0', 64),
                    'event_input_hash' => $inputHash,
                    'actor_user_id' => $actor->id,
                    'occurred_at' => $now,
                    'created_at' => $now,
                ]);
            }
            $batch->forceFill([
                'state' => ApprovalBatchState::Revoked,
                'reason_code' => 'owner_revoked',
                'reason_detail' => mb_substr($reason, 0, 500),
            ])->save();
            DB::table('execution_plan_actions')->where('workspace_id', $workspaceId)
                ->where('execution_plan_id', $plan->id)
                ->update(['state' => ExecutionPlanActionState::Revoked->value, 'updated_at' => $now]);
            DB::table('execution_plans')->where('workspace_id', $workspaceId)->where('id', $plan->id)
                ->update(['status' => ExecutionPlanStatus::Revoked->value, 'updated_at' => $now]);

            return $batch->fresh(['grants.events', 'events']) ?? $batch;
        }, 3);
    }
}
