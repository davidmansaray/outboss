<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Offer;
use App\Models\OfferVersionReviewEvent;
use Illuminate\Http\Request;

class OfferResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $offer = $this->model(Offer::class);
        $payload = [
            'id' => (string) $offer->id,
            'workspace_id' => (int) $offer->workspace_id,
            'status' => $this->enumValue($offer->getAttribute('status')) ?? (string) $offer->getRawOriginal('status'),
            'availability_state' => $this->enumValue($offer->getAttribute('availability_state')) ?? (string) $offer->getRawOriginal('availability_state'),
            'lock_version' => (int) $offer->lock_version,
            'current_version_id' => $offer->current_version_id === null ? null : (string) $offer->current_version_id,
        ];
        if ($offer->relationLoaded('currentVersion') && $offer->currentVersion !== null) {
            $payload['current_version'] = [
                'id' => (string) $offer->currentVersion->id,
                'version' => (int) $offer->currentVersion->version,
                'schema_version' => (int) $offer->currentVersion->schema_version,
                'payload' => is_array($offer->currentVersion->getAttribute('payload')) ? $offer->currentVersion->getAttribute('payload') : [],
                'status_snapshot' => $this->enumValue($offer->currentVersion->getAttribute('status_snapshot'))
                    ?? (string) $offer->currentVersion->getRawOriginal('status_snapshot'),
                'content_hash' => (string) $offer->currentVersion->content_hash,
                'origin' => $this->enumValue($offer->currentVersion->getAttribute('origin'))
                    ?? (string) $offer->currentVersion->getRawOriginal('origin'),
                'created_at' => $this->iso8601($offer->currentVersion->getAttribute('created_at')),
            ];
        }
        if ($offer->relationLoaded('currentReadinessEvaluation') && $offer->currentReadinessEvaluation !== null) {
            $payload['readiness'] = (new OfferReadinessResource($offer->currentReadinessEvaluation))->resolve();
        }
        if ($offer->relationLoaded('readinessEvaluations')) {
            $payload['readiness_evaluations'] = OfferReadinessResource::collection($offer->readinessEvaluations)->resolve();
        }
        if ($offer->relationLoaded('versionReviewEvents')) {
            $payload['review_events'] = $offer->versionReviewEvents->map(fn (OfferVersionReviewEvent $review): array => [
                'id' => (string) $review->id,
                'offer_version_id' => (string) $review->offer_version_id,
                'action' => (string) $review->action,
                'from_state' => $this->enumValue($review->getAttribute('from_state')) ?? (string) $review->getRawOriginal('from_state'),
                'to_state' => $this->enumValue($review->getAttribute('to_state')) ?? (string) $review->getRawOriginal('to_state'),
                'reason' => $review->reason,
                'actor_user_id' => $review->actor_user_id === null ? null : (int) $review->actor_user_id,
                'created_at' => $this->iso8601($review->getAttribute('created_at')),
            ])->values()->all();
        }

        return $payload;
    }
}
