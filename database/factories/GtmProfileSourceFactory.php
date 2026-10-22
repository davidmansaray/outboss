<?php

namespace Database\Factories;

use App\Models\GtmProfile;
use App\Models\GtmProfileSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GtmProfileSource>
 */
class GtmProfileSourceFactory extends Factory
{
    public function definition(): array
    {
        $excerpt = fake()->sentence();

        return [
            'gtm_profile_id' => GtmProfile::factory(),
            'source_type' => 'website',
            'canonical_url' => fake()->url(),
            'title' => fake()->sentence(4),
            'observed_at' => now(),
            'published_at' => null,
            'excerpt' => $excerpt,
            'content_hash' => hash('sha256', $excerpt),
            'excerpt_hash' => hash('sha256', $excerpt),
            'provider_operation_id' => null,
            'research_run_id' => null,
        ];
    }
}
