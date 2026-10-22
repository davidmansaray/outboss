<?php

namespace Database\Factories;

use App\Models\GtmProfile;
use App\Models\GtmProfileClientOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GtmProfileClientOperation>
 */
class GtmProfileClientOperationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'gtm_profile_id' => GtmProfile::factory(),
            'client_operation_id' => fake()->uuid(),
            'server_fingerprint' => hash('sha256', fake()->uuid()),
            'expected_lock_version' => null,
            'resulting_lock_version' => null,
            'expected_version_id' => null,
            'resulting_version_id' => null,
            'response_metadata' => [],
        ];
    }
}
