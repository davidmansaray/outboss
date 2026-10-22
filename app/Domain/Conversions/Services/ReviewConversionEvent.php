<?php

namespace App\Domain\Conversions\Services;

use App\Domain\Conversions\Enums\ConversionEventState;
use App\Domain\Conversions\Enums\ConversionReviewAction;
use App\Domain\Conversions\Support\ConversionServiceSupport;
use App\Models\ConversionEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Resolve or reject an ambiguous/unmatched event with an auditable action. */
final class ReviewConversionEvent
{
    use ConversionServiceSupport;

    public function __construct(
        private readonly MatchConversionEvent $matcher,
        private readonly RecordConversionEventReview $reviews,
    ) {}

    public function resolve(ConversionEvent $event, User $actor, int $accountId, ?string $reason = null): ConversionEvent
    {
        $this->assertMember($event->workspace, $actor);
        $prior = ConversionEventState::tryFrom((string) $event->fresh()->getRawOriginal('state')) ?? ConversionEventState::Received;
        $result = $this->matcher->handle($event, $actor, $accountId, $reason);
        $this->reviews->handle(
            $event->fresh(),
            ConversionReviewAction::Resolve,
            $actor,
            $reason,
            projectedState: ConversionEventState::Matched,
            priorState: $prior,
        );

        return $result->event->fresh();
    }

    public function reject(ConversionEvent $event, User $actor, ?string $reason = null): ConversionEvent
    {
        $this->assertMember($event->workspace, $actor);

        return DB::transaction(function () use ($event, $actor, $reason): ConversionEvent {
            $locked = ConversionEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $prior = ConversionEventState::tryFrom((string) $locked->getRawOriginal('state')) ?? ConversionEventState::Received;
            $locked->project(['state' => ConversionEventState::Rejected, 'matched_account_id' => null, 'safe_rejection_code' => 'review_rejected']);
            $updated = $locked->fresh();
            $this->reviews->handle($updated, ConversionReviewAction::Reject, $actor, $reason, projectedState: ConversionEventState::Rejected, priorState: $prior);

            return $updated;
        }, 3);
    }

    public function handle(ConversionEvent $event, User $actor, string $action, ?int $accountId = null, ?string $reason = null): ConversionEvent
    {
        return match ($action) {
            'resolve' => $accountId === null
                ? throw new RuntimeException('conversion_account_required')
                : $this->resolve($event, $actor, $accountId, $reason),
            'reject' => $this->reject($event, $actor, $reason),
            default => throw new RuntimeException('conversion_review_action_invalid'),
        };
    }
}
