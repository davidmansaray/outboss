<?php

namespace Database\Factories;

use App\Domain\Gtm\Enums\GtmProfileResearchState;
use App\Domain\Gtm\Enums\GtmProfileVersionActor;
use App\Domain\Gtm\Enums\GtmProfileVersionOrigin;
use App\Domain\Gtm\Support\CanonicalGtmProfilePayload;
use App\Models\GtmProfile;
use App\Models\GtmProfileVersion;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GtmProfile>
 */
class GtmProfileFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (GtmProfile $profile): void {
            if ($profile->workspace_id !== null) {
                return;
            }

            $profile->workspace_id = Workspace::query()->create([
                'name' => fake()->company(),
                'type' => 'personal',
            ])->id;
        })->afterCreating(function (GtmProfile $profile): void {
            // A standalone profile factory should be just as usable as a
            // provisioned workspace. Version factories opt out of this
            // callback and install the version they are creating instead.
            $existing = GtmProfileVersion::query()
                ->where('gtm_profile_id', $profile->id)
                ->orderByDesc('version')
                ->first();

            if ($existing !== null) {
                if ($profile->current_version_id === null) {
                    $profile->forceFill(['current_version_id' => $existing->id])->save();
                }

                return;
            }

            $workspace = $profile->workspace()->firstOrFail();
            $payload = CanonicalGtmProfilePayload::seed($workspace->name);
            $version = GtmProfileVersion::query()->create([
                'workspace_id' => $workspace->id,
                'gtm_profile_id' => $profile->id,
                'version' => 1,
                'schema_version' => GtmProfileVersion::SCHEMA_VERSION,
                'payload' => $payload,
                'content_hash' => CanonicalGtmProfilePayload::hash($payload),
                'actor_type' => GtmProfileVersionActor::SystemSeed,
                'actor_user_id' => null,
                'actor_display_name' => 'System',
                'origin' => GtmProfileVersionOrigin::WorkspaceSeed,
                'created_at' => now(),
            ]);

            $profile->forceFill(['current_version_id' => $version->id])->save();
        });
    }

    /**
     * Create a profile without the normal seeded-version callback.
     *
     * Version factories use this state so they can create their own first
     * version and still leave the aggregate's current pointer valid.
     */
    public function withoutSeededVersion(): static
    {
        return $this->withoutAfterCreating();
    }

    public function definition(): array
    {
        return [
            'workspace_id' => null,
            'research_state' => GtmProfileResearchState::NotStarted,
            'awaiting_reason' => null,
            'current_version_id' => null,
            'current_research_run_id' => null,
            'last_researched_at' => null,
            'last_research_failed_at' => null,
            'safe_error_code' => null,
            'last_researched_website_url' => null,
            'lock_version' => 0,
        ];
    }
}
