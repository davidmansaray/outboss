<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\DTOs\OfferVersionReference;
use App\Models\Offer;
use App\Models\OfferVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ResolveCurrentOfferVersion
{
    public function resolve(Offer $offer): OfferVersionReference
    {
        $version = OfferVersion::query()
            ->whereKey($offer->current_version_id)
            ->where('offer_id', $offer->getKey())
            ->first();

        if ($version === null) {
            throw (new ModelNotFoundException)->setModel(
                OfferVersion::class,
                [$offer->current_version_id],
            );
        }

        return new OfferVersionReference(
            offerId: (string) $version->offer_id,
            versionId: (string) $version->getKey(),
            version: (int) $version->version,
            status: $version->status_snapshot,
            contentHash: (string) $version->content_hash,
            payload: (array) $version->payload,
        );
    }

    public function handle(Offer $offer): OfferVersionReference
    {
        return $this->resolve($offer);
    }

    public function __invoke(Offer $offer): OfferVersionReference
    {
        return $this->resolve($offer);
    }
}
