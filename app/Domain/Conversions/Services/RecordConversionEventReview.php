<?php

namespace App\Domain\Conversions\Services;

use App\Domain\Conversions\Enums\ConversionEventState;
use App\Domain\Conversions\Enums\ConversionReviewAction;
use App\Models\ConversionEvent;
use App\Models\ConversionEventMatch;
use App\Models\ConversionEventReviewEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Append review/audit lineage without overwriting prior decisions. */
final class RecordConversionEventReview
{
    public function handle(
        ConversionEvent $event,
        ConversionReviewAction|string $action,
        ?User $actor = null,
        ?string $reason = null,
        ?ConversionEvent $successor = null,
        ?ConversionEventMatch $successorMatch = null,
        ?ConversionEventState $priorState = null,
        ?ConversionEventState $projectedState = null,
    ): ConversionEventReviewEvent {
        $resolvedAction = $action instanceof ConversionReviewAction
            ? $action
            : ConversionReviewAction::from($action);

        return DB::transaction(function () use ($event, $resolvedAction, $actor, $reason, $successor, $successorMatch, $priorState, $projectedState): ConversionEventReviewEvent {
            return ConversionEventReviewEvent::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $event->workspace_id,
                'conversion_event_id' => $event->id,
                'action' => $resolvedAction,
                'actor_user_id' => $actor?->id,
                'actor_display_name' => $actor?->name,
                'reason' => $reason,
                'prior_state' => $priorState ?? $event->state,
                'projected_state' => $projectedState,
                'successor_event_id' => $successor?->id,
                'successor_match_id' => $successorMatch?->id,
                'created_at' => now(),
            ]);
        }, 3);
    }

    public function record(
        ConversionEvent $event,
        ConversionReviewAction|string $action,
        ?User $actor = null,
        ?string $reason = null,
        ?ConversionEvent $successor = null,
        ?ConversionEventMatch $successorMatch = null,
        ?ConversionEventState $priorState = null,
        ?ConversionEventState $projectedState = null,
    ): ConversionEventReviewEvent {
        return $this->handle($event, $action, $actor, $reason, $successor, $successorMatch, $priorState, $projectedState);
    }
}
