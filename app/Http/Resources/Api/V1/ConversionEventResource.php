<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ConversionEvent;
use App\Models\ConversionEventMatch;
use App\Models\ConversionEventReviewEvent;
use Illuminate\Http\Request;

/** Conversion projection with safe identity handles, never raw receipt data. */
class ConversionEventResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $event = $this->model(ConversionEvent::class);
        $payload = [
            'id' => (string) $event->id,
            'workspace_id' => (int) $event->workspace_id,
            'conversion_definition_id' => (string) $event->conversion_definition_id,
            'conversion_definition_version_id' => (string) $event->conversion_definition_version_id,
            'receipt_id' => (string) $event->receipt_id,
            'source_type' => $this->enumValue($event->getAttribute('source_type')) ?? (string) $event->getRawOriginal('source_type'),
            'external_event_id' => $event->external_event_id,
            'properties' => is_array($event->getAttribute('properties')) ? $event->getAttribute('properties') : [],
            'identity_handles' => is_array($event->getAttribute('identity_handles')) ? $this->safeIdentityHandles($event->getAttribute('identity_handles')) : [],
            'occurred_at' => $this->iso8601($event->getAttribute('occurred_at')),
            'received_at' => $this->iso8601($event->getAttribute('received_at')),
            'value' => $event->value === null ? null : (string) $event->value,
            'currency' => $event->currency,
            'verification_state' => $this->enumValue($event->getAttribute('verification_state')) ?? (string) $event->getRawOriginal('verification_state'),
            'state' => $this->enumValue($event->getAttribute('state')) ?? (string) $event->getRawOriginal('state'),
            'matched_account_id' => $event->matched_account_id === null ? null : (int) $event->matched_account_id,
            'lineage_root_id' => $event->lineage_root_id === null ? null : (string) $event->lineage_root_id,
            'corrects_event_id' => $event->corrects_event_id === null ? null : (string) $event->corrects_event_id,
            'reverses_event_id' => $event->reverses_event_id === null ? null : (string) $event->reverses_event_id,
            'safe_rejection_code' => $event->safe_rejection_code,
            'attribution_state' => $event->attribution_state,
            'created_at' => $this->iso8601($event->getAttribute('created_at')),
            'updated_at' => $this->iso8601($event->getAttribute('updated_at')),
        ];
        if ($event->relationLoaded('matches')) {
            $payload['matches'] = $event->matches->map(fn (ConversionEventMatch $match): array => [
                'id' => (string) $match->id,
                'attempt_number' => (int) $match->attempt_number,
                'resolution_path' => $match->resolution_path,
                'candidate_count' => (int) $match->candidate_count,
                'result' => $this->enumValue($match->getAttribute('result')) ?? (string) $match->getRawOriginal('result'),
                'matched_account_id' => $match->matched_account_id === null ? null : (int) $match->matched_account_id,
                'reason' => $match->reason,
                'created_at' => $this->iso8601($match->getAttribute('created_at')),
            ])->values()->all();
        }
        if ($event->relationLoaded('reviewEvents')) {
            $payload['review_events'] = $event->reviewEvents->map(fn (ConversionEventReviewEvent $review): array => [
                'id' => (string) $review->id,
                'action' => $this->enumValue($review->getAttribute('action')) ?? (string) $review->getRawOriginal('action'),
                'reason' => $review->reason,
                'prior_state' => $this->enumValue($review->getAttribute('prior_state')) ?? (string) $review->getRawOriginal('prior_state'),
                'projected_state' => $this->enumValue($review->getAttribute('projected_state')) ?? (string) $review->getRawOriginal('projected_state'),
                'successor_event_id' => $review->successor_event_id === null ? null : (string) $review->successor_event_id,
                'successor_match_id' => $review->successor_match_id === null ? null : (string) $review->successor_match_id,
                'actor_user_id' => $review->actor_user_id === null ? null : (int) $review->actor_user_id,
                'created_at' => $this->iso8601($review->getAttribute('created_at')),
            ])->values()->all();
        }

        return $payload;
    }

    /**
     * @param  array<int|string, mixed>  $handles
     * @return list<array<string, mixed>>
     */
    private function safeIdentityHandles(array $handles): array
    {
        return array_values(array_map(static function (mixed $handle): array {
            if (! is_array($handle)) {
                return [];
            }

            return array_intersect_key($handle, array_flip([
                'type', 'value_hash', 'provider', 'identity_type', 'domain', 'account_id',
            ]));
        }, array_slice($handles, 0, 10)));
    }
}
