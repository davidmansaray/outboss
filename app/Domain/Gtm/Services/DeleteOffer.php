<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Services\Concerns\OfferMutationSupport;
use App\Models\Offer;
use App\Models\OfferClientOperation;
use App\Models\OfferVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DeleteOffer
{
    use OfferMutationSupport;

    public function handle(Offer $offer, User $actor): void
    {
        DB::transaction(function () use ($offer, $actor): void {
            $locked = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $this->assertOwner($locked, $actor);

            if ($this->offerStatus($locked) !== OfferStatus::Draft) {
                throw new RuntimeException('offer_not_deletable');
            }

            // Client-operation and version rows are part of this explicit
            // aggregate purge. Query deletes intentionally bypass the
            // immutable-version model guard; normal version updates/deletes
            // remain prohibited.
            $locked->forceFill(['current_version_id' => null])->save();
            OfferClientOperation::query()->where('offer_id', $locked->id)->delete();
            OfferVersion::query()->where('offer_id', $locked->id)->delete();
            $locked->delete();
        }, 3);
    }

    public function delete(Offer $offer, User $actor): void
    {
        $this->handle($offer, $actor);
    }
}
