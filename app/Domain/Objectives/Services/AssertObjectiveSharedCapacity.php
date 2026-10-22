<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\ExecutionOrigin;
use App\Domain\Objectives\Exceptions\ApprovalConflict;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\CampaignComposition;
use App\Models\ExecutionAuthorityLegacyMapping;
use App\Models\ExecutionAuthorityRevocation;
use App\Models\ExecutionAuthorityScope;
use App\Models\ExecutionPlan;
use App\Models\ExecutionPlanAction;
use App\Models\Objective;
use App\Models\ObjectiveGoalVersion;
use App\Models\ObjectiveRun;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Approval grants are the commitment ledger; expiry does not prove a send was absent. */
final class AssertObjectiveSharedCapacity
{
    /** @param Collection<int, ExecutionPlanAction> $actions */
    public function handle(ExecutionPlan $plan, Collection $actions): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Shared capacity must be checked inside the approval transaction.');
        }
        $run = ObjectiveRun::query()->where('workspace_id', $plan->workspace_id)->whereKey($plan->objective_run_id)->firstOrFail();
        $objective = Objective::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($run->objective_id)->lockForUpdate()->firstOrFail();
        $goal = ObjectiveGoalVersion::query()->where('workspace_id', $plan->workspace_id)
            ->where('objective_id', $objective->id)->whereKey($objective->current_goal_version_id)->lockForUpdate()->first();
        $mapping = ExecutionAuthorityLegacyMapping::query()->where('workspace_id', $plan->workspace_id)
            ->where('objective_id', $objective->id)->lockForUpdate()->first();
        $scopeId = $mapping?->execution_authority_scope_id;
        if ($plan->getAttribute('origin') === ExecutionOrigin::Human) {
            $composition = CampaignComposition::query()->where('workspace_id', $plan->workspace_id)
                ->whereKey($run->getAttribute('campaign_composition_id'))->lockForUpdate()->first();
            $pinned = $composition === null ? null : ObjectiveGoalVersion::query()
                ->where('workspace_id', $plan->workspace_id)->where('objective_id', $objective->id)
                ->whereKey($composition->objective_goal_version_id)->lockForUpdate()->first();
            if ($pinned === null || $run->getAttribute('origin') !== ExecutionOrigin::Human || $composition->execution_authority_scope_id === null) {
                throw new ApprovalConflict('execution_scope_missing');
            }
            if ($pinned->execution_authority !== null && $mapping === null) {
                throw new ApprovalConflict('legacy_authority_migration_required');
            }
            $scopeId = $composition->execution_authority_scope_id;
            if ($composition->sending_starts_at !== null && $composition->sending_ends_at !== null
                && ! $composition->sending_ends_at->greaterThan($composition->sending_starts_at)) {
                throw new ApprovalConflict('campaign_window_missing');
            }
            foreach ($actions as $action) {
                if (($composition->sending_starts_at !== null && $action->scheduled_not_before->lessThan($composition->sending_starts_at))
                    || ($composition->sending_ends_at !== null && $action->scheduled_not_after->greaterThan($composition->sending_ends_at))) {
                    throw new ApprovalConflict('campaign_window_exceeded');
                }
            }
        }
        $scope = $scopeId === null ? null : ExecutionAuthorityScope::query()->where('workspace_id', $plan->workspace_id)
            ->whereKey($scopeId)->lockForUpdate()->firstOrFail();
        if ($scope === null && $goal === null) {
            return; // Unmigrated AI-only Objectives retain their existing contract.
        }
        $authority = $scope !== null ? $scope->authority : $goal->execution_authority;
        foreach (['maximum_people', 'maximum_accounts', 'maximum_messages'] as $field) {
            if (! is_array($authority) || ! is_int($authority[$field] ?? null) || $authority[$field] < 1) {
                throw new ApprovalConflict('objective_shared_authority_missing');
            }
        }
        if ($scope !== null) {
            if (ExecutionAuthorityRevocation::query()->where('workspace_id', $plan->workspace_id)
                ->where('execution_authority_scope_id', $scope->id)->lockForUpdate()->first() !== null) {
                throw new ApprovalConflict('execution_scope_revoked', message: 'This execution allowance was revoked.');
            }
            if (! hash_equals($scope->content_hash, CanonicalObjectivePayload::hash(['name' => $scope->name, 'authority' => $authority]))) {
                throw new ApprovalConflict('execution_scope_changed');
            }
            $start = Carbon::parse($authority['starts_at']);
            $end = Carbon::parse($authority['ends_at']);
            if (! $end->isFuture()) {
                throw new ApprovalConflict('execution_scope_expired');
            }
            foreach ($actions as $action) {
                if ($action->scheduled_not_before->lessThan($start) || $action->scheduled_not_after->greaterThan($end)) {
                    throw new ApprovalConflict('execution_scope_window_exceeded');
                }
            }
            if ($mapping !== null && (string) $mapping->execution_authority_scope_id === (string) $scope->id
                && ! hash_equals(CanonicalObjectivePayload::hash($authority), CanonicalObjectivePayload::hash($goal->execution_authority ?? []))) {
                throw new ApprovalConflict('legacy_authority_changed');
            }
        }

        // Use a current locking read, not a repeatable-read snapshot established
        // by earlier manifest queries. Concurrent approvals serialize on the scope
        // (or the Objective for legacy authority that has not been mapped).
        // Retain revoked/expired/unknown commitments until positive no-send release
        // is implemented; never refund capacity merely because approval expired.
        $committed = app(ExecutionAllowanceLedger::class)->committed((int) $plan->workspace_id, $scope?->id, $objective->id, lock: true);
        foreach ($actions as $action) {
            if ((int) $action->workspace_id !== (int) $plan->workspace_id || $action->execution_plan_id !== $plan->id) {
                throw new ApprovalConflict('action_set_changed');
            }
            $committed->put($action->id, (object) [
                'id' => $action->id, 'person_id' => $action->person_id, 'account_id' => $action->account_id,
            ]);
        }
        if ($committed->contains(fn ($action): bool => $action->person_id === null || $action->account_id === null)) {
            throw new ApprovalConflict('objective_shared_capacity_lineage_missing');
        }
        if ($committed->count() > $authority['maximum_messages']
            || $committed->pluck('person_id')->unique()->count() > $authority['maximum_people']
            || $committed->pluck('account_id')->unique()->count() > $authority['maximum_accounts']) {
            throw new ApprovalConflict('objective_shared_capacity_exceeded', message: 'This plan exceeds the execution allowance already committed across campaigns.');
        }
    }
}
