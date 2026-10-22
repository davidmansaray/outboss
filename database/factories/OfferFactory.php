<?php

namespace Database\Factories;

use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Enums\OfferVersionOrigin;
use App\Domain\Gtm\Support\CanonicalOfferPayload;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Offer $offer): void {
            // Keep direct Offer::factory() fixtures valid for services that
            // resolve the current immutable version. OfferVersionFactory
            // opts out of this callback when it is creating a child row.
            $existing = OfferVersion::query()
                ->where('offer_id', $offer->id)
                ->orderByDesc('version')
                ->first();

            if ($existing !== null) {
                if ($offer->current_version_id === null) {
                    $offer->forceFill(['current_version_id' => $existing->id])->save();
                }

                return;
            }

            $status = OfferStatus::tryFrom((string) $offer->getRawOriginal('status')) ?? OfferStatus::Draft;

            // A non-draft status supplied directly to a fixture represents a
            // caller-owned state and may require meaningful content. Leave
            // those explicit fixtures alone; the normal draft factory still
            // guarantees a current version for service tests.
            if ($status !== OfferStatus::Draft) {
                return;
            }

            $payload = CanonicalOfferPayload::empty();
            $version = OfferVersion::query()->create([
                'workspace_id' => $offer->workspace_id,
                'offer_id' => $offer->id,
                'version' => 1,
                'schema_version' => 1,
                'payload' => $payload,
                'status_snapshot' => $status,
                'content_hash' => CanonicalOfferPayload::hash($payload, $status),
                'origin' => OfferVersionOrigin::Created,
                'from_status' => null,
                'actor_type' => 'system_seed',
                'actor_user_id' => null,
                'actor_display_name' => 'System',
                'created_at' => now(),
            ]);

            $offer->forceFill(['current_version_id' => $version->id])->save();
        });
    }

    /**
     * Create an Offer without the normal seeded-version callback.
     */
    public function withoutSeededVersion(): static
    {
        return $this->withoutAfterCreating();
    }

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'current_version_id' => null,
            'status' => OfferStatus::Draft,
            'lock_version' => 0,
        ];
    }
}
