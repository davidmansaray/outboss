<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Enums\OfferReadinessState;
use App\Models\Offer;
use App\Models\OfferVersionReviewEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReviewOfferVersion
{
    public function __construct(private readonly EvaluateOfferReadiness $readiness) {}

    public function handle(Offer $offer, User $actor, string $action, ?string $reason = null): Offer
    {
        return DB::transaction(function () use ($offer, $actor, $action, $reason): Offer {
            $locked = Offer::query()->where('workspace_id', $offer->workspace_id)->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if (! $locked->workspace()->firstOrFail()->isOwner($actor)) {
                throw new AuthorizationException('Only the workspace owner can review Offer Versions.');
            }
            if (! in_array($action, ['submit', 'approve', 'reject', 'reopen'], true)) {
                throw ValidationException::withMessages(['action' => 'Choose a supported review action.']);
            }

            $before = $this->readiness->handle($locked, 'review');
            $from = $before->state;
            $to = match ($action) {
                'submit' => OfferReadinessState::ReadyForReview,
                'approve' => OfferReadinessState::Approved,
                'reject' => OfferReadinessState::Blocked,
                'reopen' => OfferReadinessState::Incomplete,
            };
            if (in_array($action, ['submit', 'approve'], true) && $before->blockers !== []) {
                throw ValidationException::withMessages(['readiness' => 'Resolve every readiness blocker before review or approval.']);
            }
            if ($action === 'approve' && $from !== OfferReadinessState::ReadyForReview) {
                throw ValidationException::withMessages(['action' => 'Submit the current Offer Version for review before approval.']);
            }

            OfferVersionReviewEvent::query()->create([
                'workspace_id' => $locked->workspace_id,
                'offer_id' => $locked->id,
                'offer_version_id' => $locked->current_version_id,
                'action' => $action,
                'from_state' => $from,
                'to_state' => $to,
                'actor_user_id' => $actor->id,
                'actor_display_name' => $actor->name,
                'reason' => $reason !== null ? mb_substr(trim($reason), 0, 1000) : null,
                'policy_hash' => $before->dependency_hash,
                'created_at' => now(),
            ]);
            $this->readiness->handle($locked, 'review_'.$action);

            return $locked->fresh(['currentVersion', 'currentReadinessEvaluation']);
        }, 3);
    }
}
