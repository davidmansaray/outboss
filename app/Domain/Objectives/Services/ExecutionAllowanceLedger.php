<?php

namespace App\Domain\Objectives\Services;

use App\Models\ExecutionAuthorityLegacyMapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** All approved grants remain committed, including expired/revoked approvals. */
final class ExecutionAllowanceLedger
{
    /** @return Collection<int|string, \stdClass> */
    public function committed(int $workspaceId, ?string $scopeId, ?string $objectiveId = null, bool $lock = false): Collection
    {
        $legacyQuery = ExecutionAuthorityLegacyMapping::query()->where('workspace_id', $workspaceId)->where('execution_authority_scope_id', $scopeId);
        $legacy = $scopeId === null ? null : ($lock ? $legacyQuery->lockForUpdate() : $legacyQuery)->first();
        $query = DB::table('approval_grants as grants')
            ->join('execution_plan_actions as actions', function ($join): void {
                $join->on('actions.workspace_id', '=', 'grants.workspace_id')->on('actions.id', '=', 'grants.execution_plan_action_id');
            })
            ->join('execution_plans as plans', function ($join): void {
                $join->on('plans.workspace_id', '=', 'actions.workspace_id')->on('plans.id', '=', 'actions.execution_plan_id');
            })
            ->join('objective_runs as runs', function ($join): void {
                $join->on('runs.workspace_id', '=', 'plans.workspace_id')->on('runs.id', '=', 'plans.objective_run_id');
            })
            ->leftJoin('campaign_compositions as compositions', function ($join): void {
                $join->on('compositions.workspace_id', '=', 'runs.workspace_id')->on('compositions.id', '=', 'runs.campaign_composition_id');
            })
            ->where('grants.workspace_id', $workspaceId)
            ->where(function ($query) use ($scopeId, $objectiveId, $legacy): void {
                if ($scopeId === null) {
                    $query->where('runs.objective_id', $objectiveId);

                    return;
                }
                $query->where('compositions.execution_authority_scope_id', $scopeId);
                if ($legacy !== null) {
                    $query->orWhere(fn ($legacyQuery) => $legacyQuery->where('runs.objective_id', $legacy->objective_id)->whereNull('compositions.execution_authority_scope_id'));
                }
            })->where('grants.decision', 'approved')->orderBy('actions.id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get(['actions.id', 'actions.person_id', 'actions.account_id', 'actions.execution_plan_id'])->keyBy('id');
    }
}
