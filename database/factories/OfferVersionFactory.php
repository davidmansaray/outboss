<?php

namespace Database\Factories;

use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Enums\OfferVersionOrigin;
use App\Domain\Gtm\Support\CanonicalOfferPayload;
use App\Models\Offer;
use App\Models\OfferVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfferVersion>
 */
class OfferVersionFactory extends Factory
{
    public function definition(): array
    {
        $payload = CanonicalOfferPayload::empty();

        return [
            'offer_id' => Offer::factory()->withoutSeededVersion(),
            'workspace_id' => function (array $attributes): int {
                return (int) Offer::query()
                    ->whereKey($attributes['offer_id'] ?? null)
                    ->value('workspace_id');
            },
            'version' => function (array $attributes): int {
                $offerId = $attributes['offer_id'] ?? null;
                if (! is_string($offerId) || $offerId === '') {
                    return 1;
                }

                return ((int) OfferVersion::query()
                    ->where('offer_id', $offerId)
                    ->max('version')) + 1;
            },
            'schema_version' => 1,
            'payload' => $payload,
            'status_snapshot' => function (array $attributes): OfferStatus {
                $status = $attributes['status_snapshot'] ?? null;
                if ($status instanceof OfferStatus) {
                    return $status;
                }

                $offerId = $attributes['offer_id'] ?? null;
                $offerStatus = is_string($offerId)
                    ? Offer::query()->whereKey($offerId)->value('status')
                    : null;

                if ($offerStatus instanceof OfferStatus) {
                    return $offerStatus;
                }

                return OfferStatus::tryFrom((string) $offerStatus) ?? OfferStatus::Draft;
            },
            'content_hash' => function (array $attributes) use ($payload): string {
                $versionPayload = $attributes['payload'] ?? $payload;
                $versionPayload = is_array($versionPayload) ? $versionPayload : $payload;
                $status = $attributes['status_snapshot'] ?? OfferStatus::Draft;
                $status = $status instanceof OfferStatus
                    ? $status
                    : (OfferStatus::tryFrom((string) $status) ?? OfferStatus::Draft);

                return CanonicalOfferPayload::hash($versionPayload, $status);
            },
            'origin' => OfferVersionOrigin::Created,
            'from_status' => null,
            'actor_type' => 'system_seed',
            'actor_user_id' => null,
            'actor_display_name' => 'System',
            'created_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (OfferVersion $version): void {
            $offer = Offer::query()->whereKey($version->offer_id)->first();
            if ($offer !== null && $offer->current_version_id === null) {
                $offer->forceFill(['current_version_id' => $version->id])->save();
            }
        });
    }
}
