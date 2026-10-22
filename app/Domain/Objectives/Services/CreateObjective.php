<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\ObjectiveStatus;
use App\Models\Objective;
use App\Models\ObjectiveStateEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class CreateObjective
{
    public function handle(Workspace $workspace, User $actor): Objective
    {
        if (! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can create Objectives.');
        }

        return DB::transaction(function () use ($workspace, $actor): Objective {
            $objective = Objective::query()->create([
                'workspace_id' => $workspace->id,
                'status' => ObjectiveStatus::Draft,
                'lock_version' => 1,
                'created_by_user_id' => $actor->id,
                'last_actor_user_id' => $actor->id,
            ]);
            ObjectiveStateEvent::query()->create([
                'workspace_id' => $workspace->id,
                'objective_id' => $objective->id,
                'objective_version_id' => null,
                'from_state' => null,
                'to_state' => ObjectiveStatus::Draft,
                'reason_code' => 'created',
                'context' => ['schema_version' => 'objective_v1'],
                'actor_user_id' => $actor->id,
            ]);

            return $objective;
        }, 3);
    }
}
