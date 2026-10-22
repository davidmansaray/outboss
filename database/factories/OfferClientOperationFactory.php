<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OfferClientOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfferClientOperation>
 */
class OfferClientOperationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
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
