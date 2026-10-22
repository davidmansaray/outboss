<?php

namespace Database\Factories;

use App\Domain\Gtm\Enums\GtmResearchRunState;
use App\Models\GtmProfile;
use App\Models\GtmProfileResearchRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GtmProfileResearchRun>
 */
class GtmProfileResearchRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'gtm_profile_id' => GtmProfile::factory(),
            'state' => GtmResearchRunState::Queued,
            'awaiting_reason' => null,
            'budget_run_id' => null,
            'parent_run_id' => null,
            'lineage_type' => null,
            'website_url_snapshot' => null,
            'base_profile_version_id' => null,
            'input_hash' => hash('sha256', fake()->uuid()),
            'initiated_by_user_id' => null,
            'safe_failure_code' => null,
            'queued_at' => now(),
            'started_at' => null,
            'finished_at' => null,
            'failed_at' => null,
        ];
    }
}
