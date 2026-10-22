<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait BelongsToWorkspace
{
    /**
     * Determine whether the model belongs to the user's active workspace.
     *
     * currentWorkspace() verifies membership and binds API tokens to their
     * creation workspace. Requiring an exact match prevents a member of two
     * workspaces from reaching records outside the active context by ID.
     */
    protected function memberOfWorkspace(User $user, Model $model, string $workspaceIdAttribute = 'workspace_id'): bool
    {
        $workspaceId = (int) $model->getAttribute($workspaceIdAttribute);

        return $workspaceId > 0
            && $workspaceId === (int) $user->currentWorkspace()->id;
    }
}
