<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\ObjectiveRunDispatchState;
use App\Jobs\Objectives\PublishObjectiveRunDispatches;
use App\Models\ObjectiveRunDispatch;

final class SweepObjectiveRunDispatches
{
    public function handle(?int $limit = null, ?int $workspaceId = null): int
    {
        $ids = ObjectiveRunDispatch::query()
            ->where('state', ObjectiveRunDispatchState::Pending->value)
            ->where('available_at', '<=', now())
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->orderBy('id')->limit(max(1, min(1000, $limit ?? 100)))->pluck('id');
        foreach ($ids as $id) {
            PublishObjectiveRunDispatches::dispatch((string) $id);
        }

        return $ids->count();
    }
}
