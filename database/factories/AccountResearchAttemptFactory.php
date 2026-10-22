<?php

namespace Database\Factories;

use App\Models\AccountResearchAttempt;
use App\Models\AccountResearchRun;
use App\Models\AccountResearchStage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AccountResearchAttempt> */
class AccountResearchAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => fn (array $attributes): int => (int) AccountResearchRun::query()->whereKey($attributes['research_run_id'])->value('workspace_id'),
            'research_run_id' => AccountResearchRun::factory(),
            'stage_id' => AccountResearchStage::factory(),
            'attempt_number' => 1,
            'deterministic_idempotency_key' => (string) Str::uuid(),
            'provider_operation_id' => null,
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'state' => 'planned',
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
