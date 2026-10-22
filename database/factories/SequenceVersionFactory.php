<?php

namespace Database\Factories;

use App\Domain\Campaigns\Enums\SequenceVersionStatus;
use App\Models\Campaign;
use App\Models\SequenceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SequenceVersion>
 */
class SequenceVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'version' => 1,
            'status' => SequenceVersionStatus::Draft,
        ];
    }
}
