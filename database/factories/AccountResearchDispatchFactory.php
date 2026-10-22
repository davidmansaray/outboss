<?php

namespace Database\Factories;

use App\Models\AccountResearchDispatch;
use App\Models\AccountResearchRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AccountResearchDispatch> */
class AccountResearchDispatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => fn (array $attributes): int => (int) AccountResearchRun::query()->whereKey($attributes['research_run_id'])->value('workspace_id'),
            'research_run_id' => AccountResearchRun::factory(),
            'dispatch_kind' => 'stage',
            'stage_key' => 'eligibility',
            'state' => 'pending',
            'available_at' => now(),
            'published_at' => null,
            'publish_lease_owner' => null,
            'publish_lease_expires_at' => null,
            'publish_attempts' => 0,
            'safe_error_code' => null,
        ];
    }
}
