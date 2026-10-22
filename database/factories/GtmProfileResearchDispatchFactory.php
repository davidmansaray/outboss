<?php

namespace Database\Factories;

use App\Models\GtmProfileResearchDispatch;
use App\Models\GtmProfileResearchRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GtmProfileResearchDispatch>
 */
class GtmProfileResearchDispatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'research_run_id' => GtmProfileResearchRun::factory(),
            'gtm_profile_id' => function (array $attributes): ?string {
                $runId = $attributes['research_run_id'] ?? null;
                if (! is_string($runId) || $runId === '') {
                    return null;
                }

                $profileId = GtmProfileResearchRun::query()
                    ->whereKey($runId)
                    ->value('gtm_profile_id');

                return $profileId === null ? null : (string) $profileId;
            },
            'status' => 'pending',
            'available_at' => now(),
            'published_at' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'publish_attempts' => 0,
            'last_error_code' => null,
        ];
    }
}
