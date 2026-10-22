<?php

namespace Database\Factories;

use App\Domain\Intelligence\Enums\AccountResearchStageKey;
use App\Models\AccountResearchRun;
use App\Models\AccountResearchStage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AccountResearchStage> */
class AccountResearchStageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => fn (array $attributes): int => (int) AccountResearchRun::query()->whereKey($attributes['research_run_id'])->value('workspace_id'),
            'research_run_id' => AccountResearchRun::factory(),
            'stage_order' => 1,
            'stage_key' => AccountResearchStageKey::Eligibility,
            'state' => 'pending',
            'entry_predicate_snapshot' => [],
            'missing_facts_snapshot' => [],
            'expected_cost_micros' => 0,
            'maximum_cost_micros' => 1_500_000,
            'implementation_version' => 1,
            'checkpoint_reference' => null,
            'checkpoint_hash' => null,
            'lease_owner' => null,
            'fencing_token' => 0,
            'lease_expires_at' => null,
            'safe_error_code' => null,
            'started_at' => null,
            'succeeded_at' => null,
            'failed_at' => null,
        ];
    }
}
