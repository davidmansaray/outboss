<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Enums\OfferReadinessState;
use App\Domain\Gtm\Enums\OfferStatus;
use App\Models\ConversionEvent;
use App\Models\FulfilmentRequest;
use App\Models\Offer;
use Illuminate\Support\Collection;

/**
 * Evaluate a matched conversion against active operational Offers.
 *
 * The actual request writer owns the offer/version/conversion checks and its
 * deterministic idempotency key. Keeping this coordinator small means every
 * intake transport gets identical fulfilment behavior without gaining any
 * campaign, message, or sending authority.
 */
final class EvaluateConversionFulfilment
{
    public function __construct(private readonly CreateFulfilmentRequest $create) {}

    /** @return Collection<int,FulfilmentRequest> */
    public function handle(ConversionEvent $event): Collection
    {
        $event = $event->fresh();
        if ($event === null || $event->matched_account_id === null) {
            return collect();
        }

        $requests = collect();
        Offer::query()
            ->where('workspace_id', $event->workspace_id)
            ->where('status', OfferStatus::Active->value)
            ->where('availability_state', OfferReadinessState::Approved->value)
            ->orderBy('id')
            ->each(function (Offer $offer) use ($event, $requests): void {
                $request = $this->create->handle($offer, $event);
                if ($request !== null) {
                    $requests->push($request);
                }
            });

        return $requests;
    }
}
