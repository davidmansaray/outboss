<?php

namespace Database\Factories;

use App\Domain\Gtm\Enums\GtmProfileVersionActor;
use App\Domain\Gtm\Enums\GtmProfileVersionOrigin;
use App\Domain\Gtm\Support\CanonicalGtmProfilePayload;
use App\Models\GtmProfile;
use App\Models\GtmProfileVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * @extends Factory<GtmProfileVersion>
 */
class GtmProfileVersionFactory extends Factory
{
    public function definition(): array
    {
        $payload = CanonicalGtmProfilePayload::empty();

        return [
            'gtm_profile_id' => GtmProfile::factory()->withoutSeededVersion(),
            'workspace_id' => function (array $attributes): int {
                $profileId = $attributes['gtm_profile_id'] ?? null;
                if (! is_string($profileId) || $profileId === '') {
                    throw new LogicException('A GTM profile version factory requires a persisted profile.');
                }

                return (int) GtmProfile::query()->whereKey($profileId)->value('workspace_id');
            },
            'version' => function (array $attributes): int {
                $profileId = $attributes['gtm_profile_id'] ?? null;
                if (! is_string($profileId) || $profileId === '') {
                    return 1;
                }

                return ((int) GtmProfileVersion::query()
                    ->where('gtm_profile_id', $profileId)
                    ->max('version')) + 1;
            },
            'schema_version' => GtmProfileVersion::SCHEMA_VERSION,
            'payload' => $payload,
            'content_hash' => function (array $attributes) use ($payload): string {
                $versionPayload = $attributes['payload'] ?? $payload;

                return CanonicalGtmProfilePayload::hash(
                    is_array($versionPayload) ? $versionPayload : $payload,
                );
            },
            'actor_type' => GtmProfileVersionActor::SystemSeed,
            'actor_user_id' => null,
            'actor_display_name' => 'System',
            'origin' => GtmProfileVersionOrigin::WorkspaceSeed,
            'created_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (GtmProfileVersion $version): void {
            $profile = GtmProfile::query()->whereKey($version->gtm_profile_id)->first();
            if ($profile !== null && $profile->current_version_id === null) {
                $profile->forceFill(['current_version_id' => $version->id])->save();
            }
        });
    }
}
