<?php

namespace App\Domain\Objectives\Services;

use App\Models\ExecutionAuthorityRevocation;
use App\Models\ExecutionAuthorityScope;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RevokeExecutionAuthorityScope
{
    public function handle(ExecutionAuthorityScope $scope, User $actor, string $reason): ExecutionAuthorityRevocation
    {
        $workspace = $actor->currentWorkspace();
        if ((int) $scope->workspace_id !== (int) $workspace->id || ! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the current workspace owner can revoke this allowance.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Provide a reason of at most 500 characters.']);
        }

        return DB::transaction(function () use ($scope, $actor, $reason): ExecutionAuthorityRevocation {
            ExecutionAuthorityScope::query()->where('workspace_id', $scope->workspace_id)->whereKey($scope->id)->lockForUpdate()->firstOrFail();

            return ExecutionAuthorityRevocation::query()->firstOrCreate([
                'workspace_id' => $scope->workspace_id, 'execution_authority_scope_id' => $scope->id,
            ], ['created_by_user_id' => $actor->id, 'reason' => $reason]);
        }, 3);
    }
}
