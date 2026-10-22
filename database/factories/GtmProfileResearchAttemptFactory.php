<?php

namespace Database\Factories;

use App\Models\GtmProfileResearchAttempt;
use App\Models\GtmProfileResearchPhase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GtmProfileResearchAttempt>
 */
class GtmProfileResearchAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'phase_id' => GtmProfileResearchPhase::factory(),
            'research_run_id' => function (array $attributes): ?string {
                $phaseId = $attributes['phase_id'] ?? null;
                if (! is_int($phaseId) && ! is_string($phaseId)) {
                    return null;
                }

                $runId = GtmProfileResearchPhase::query()
                    ->whereKey($phaseId)
                    ->value('research_run_id');

                return $runId === null ? null : (string) $runId;
            },
            'attempt_number' => 1,
            'deterministic_idempotency_key' => fake()->unique()->uuid(),
            'provider_operation_id' => null,
            'input_hash' => hash('sha256', fake()->uuid()),
            'status' => 'planned',
            'lease_owner' => null,
            'fencing_token' => 0,
            'lease_expires_at' => null,
            'outcome' => null,
            'safe_error_code' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
