<?php

namespace Database\Factories;

use App\Domain\Gtm\Enums\GtmProfileReviewStatus;
use App\Domain\Gtm\Enums\GtmProfileReviewType;
use App\Models\GtmProfile;
use App\Models\GtmProfileReviewItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GtmProfileReviewItem>
 */
class GtmProfileReviewItemFactory extends Factory
{
    public function definition(): array
    {
        $proposedValue = ['value' => fake()->sentence()];

        return [
            'gtm_profile_id' => GtmProfile::factory(),
            'field_path' => 'company.product_description',
            'type' => GtmProfileReviewType::Conflict,
            'proposed_value' => $proposedValue,
            'source_ids' => [],
            'proposed_value_hash' => hash('sha256', json_encode($proposedValue, JSON_THROW_ON_ERROR)),
            'status' => GtmProfileReviewStatus::Open,
            'resolved_value' => null,
            'resolved_version_id' => null,
            'resolved_by_user_id' => null,
            'resolved_at' => null,
        ];
    }
}
