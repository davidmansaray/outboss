<?php

namespace Database\Factories;

use App\Domain\Gtm\Enums\GtmResearchPhaseStatus;
use App\Models\GtmProfileResearchPhase;
use App\Models\GtmProfileResearchRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GtmProfileResearchPhase>
 */
class GtmProfileResearchPhaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'research_run_id' => GtmProfileResearchRun::factory(),
            'phase' => 'website',
            'status' => GtmResearchPhaseStatus::Pending,
            'successful_checkpoint' => null,
            'checkpoint_hash' => null,
            'started_at' => null,
            'succeeded_at' => null,
            'failed_at' => null,
        ];
    }
}
