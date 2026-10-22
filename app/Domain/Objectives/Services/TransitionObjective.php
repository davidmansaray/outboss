<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\ObjectiveStatus;
use App\Models\Objective;
use App\Models\ObjectiveStateEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class TransitionObjective
{
    public function __construct(private readonly ObjectiveReadiness $readiness) {}

    public function handle(Objective $objective, User $actor, ObjectiveStatus|string $target, int $expectedLockVersion, ?string $reasonCode = null): Objective
    {
        $target = $target instanceof ObjectiveStatus ? $target : ObjectiveStatus::tryFrom($target);
        if ($target === null) {
            throw ValidationException::withMessages(['status' => 'The Objective status is invalid.']);
        }

        return DB::transaction(function () use ($objective, $actor, $target, $expectedLockVersion, $reasonCode): Objective {
            $locked = Objective::query()->where('workspace_id', $objective->workspace_id)
                ->whereKey($objective->id)->lockForUpdate()->firstOrFail();
            if (! $locked->workspace()->firstOrFail()->isOwner($actor)) {
                throw new AuthorizationException('Only the workspace owner can transition an Objective.');
            }
            if ((int) $locked->lock_version !== $expectedLockVersion) {
                throw new RuntimeException('stale_version');
            }
            $from = $locked->status;
            if (! $from->canTransitionTo($target)) {
                throw ValidationException::withMessages(['status' => "An Objective cannot move from {$from->value} to {$target->value}."]);
            }
            $context = [];
            if ($target === ObjectiveStatus::Ready) {
                $result = $this->readiness->handle($locked);
                $context = ['readiness_input_hash' => $result->inputHash, 'warnings' => $result->warnings];
                if (! $result->ready()) {
                    throw ValidationException::withMessages(['readiness' => $result->blockers]);
                }
            }
            if ($target === ObjectiveStatus::Active) {
                throw ValidationException::withMessages(['status' => 'objective_launch_required']);
            }

            $timestamps = match ($target) {
                ObjectiveStatus::Ready => ['ready_at' => now()],
                ObjectiveStatus::Paused => ['paused_at' => now()],
                ObjectiveStatus::Completed => ['completed_at' => now()],
                ObjectiveStatus::Expired => ['expired_at' => now()],
                ObjectiveStatus::Cancelled => ['cancelled_at' => now()],
                default => [],
            };
            $locked->forceFill([
                'status' => $target,
                'status_reason_code' => $reasonCode,
                'last_actor_user_id' => $actor->id,
                'lock_version' => $expectedLockVersion + 1,
                ...$timestamps,
            ])->save();
            ObjectiveStateEvent::query()->create([
                'workspace_id' => $locked->workspace_id,
                'objective_id' => $locked->id,
                'objective_version_id' => $locked->current_version_id,
                'from_state' => $from,
                'to_state' => $target,
                'reason_code' => $reasonCode,
                'context' => $context,
                'actor_user_id' => $actor->id,
            ]);

            return $locked->fresh(['currentVersion', 'stateEvents']);
        }, 3);
    }
}
