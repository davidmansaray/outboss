<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\ExecutionAuthorityLegacyMapping;
use App\Models\ExecutionAuthorityScope;
use App\Models\Objective;
use App\Models\ObjectiveGoalVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Explicit owner migration. Never rewrites goals, runs, actions or grants. */
final class ImportLegacyExecutionAuthority
{
    public function handle(Objective $objective, User $actor): ExecutionAuthorityScope
    {
        $workspace = $actor->currentWorkspace();
        if ((int) $objective->workspace_id !== (int) $workspace->id || ! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the current workspace owner can migrate execution authority.');
        }

        return DB::transaction(function () use ($workspace, $objective, $actor): ExecutionAuthorityScope {
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $objective = Objective::query()->where('workspace_id', $workspace->id)->whereKey($objective->id)->lockForUpdate()->firstOrFail();
            $versions = ObjectiveGoalVersion::query()->where('workspace_id', $workspace->id)
                ->where('objective_id', $objective->id)->orderBy('version')->lockForUpdate()->get();
            $current = $versions->firstWhere('id', $objective->current_goal_version_id);
            if ($current === null || $current->execution_authority === null) {
                throw ValidationException::withMessages(['authority' => 'There is no current legacy authority to migrate.']);
            }
            $authority = app(ValidateExecutionAuthority::class)->handle($current->execution_authority);
            $authorityHash = CanonicalObjectivePayload::hash($authority);
            foreach ($versions as $version) {
                if ($version->execution_authority !== null && ! hash_equals($authorityHash,
                    CanonicalObjectivePayload::hash(app(ValidateExecutionAuthority::class)->handle($version->execution_authority)))) {
                    throw ValidationException::withMessages(['authority' => 'Historical authority limits differ. Reconcile them explicitly before migration; no allowance was created.']);
                }
            }
            $mapping = ExecutionAuthorityLegacyMapping::query()->where('workspace_id', $workspace->id)
                ->where('objective_id', $objective->id)->lockForUpdate()->first();
            if ($mapping !== null) {
                $scope = ExecutionAuthorityScope::query()->where('workspace_id', $workspace->id)
                    ->whereKey($mapping->execution_authority_scope_id)->lockForUpdate()->firstOrFail();
                if (! hash_equals($authorityHash, CanonicalObjectivePayload::hash($scope->authority))) {
                    throw ValidationException::withMessages(['authority' => 'The migrated authority no longer matches the historical allowance.']);
                }

                return $scope;
            }
            $name = 'Migrated allowance — '.mb_substr($current->name, 0, 130);
            // Expired historical windows are preserved, never extended to now.
            $scope = ExecutionAuthorityScope::query()->create([
                'workspace_id' => $workspace->id, 'name' => $name, 'authority' => $authority,
                'content_hash' => CanonicalObjectivePayload::hash(['name' => $name, 'authority' => $authority]),
                'operation_id' => (string) Str::uuid(), 'created_by_user_id' => $actor->id,
            ]);
            ExecutionAuthorityLegacyMapping::query()->create([
                'workspace_id' => $workspace->id, 'objective_id' => $objective->id,
                'source_goal_version_id' => $current->id, 'execution_authority_scope_id' => $scope->id,
                'created_by_user_id' => $actor->id,
            ]);

            return $scope;
        }, 3);
    }
}
