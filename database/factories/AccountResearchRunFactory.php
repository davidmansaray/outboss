<?php

namespace Database\Factories;

use App\Domain\Intelligence\Enums\AccountResearchRunState;
use App\Models\Account;
use App\Models\AccountResearchRun;
use App\Models\GtmProfileVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AccountResearchRun> */
class AccountResearchRunFactory extends Factory
{
    public function definition(): array
    {
        $account = Account::factory();

        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => fn (array $attributes): int => (int) Account::query()->whereKey($attributes['account_id'])->value('workspace_id'),
            'account_id' => $account,
            'gtm_profile_version_id' => fn (array $attributes): ?string => GtmProfileVersion::query()->whereHas('profile', fn ($query) => $query->where('workspace_id', Account::query()->whereKey($attributes['account_id'])->value('workspace_id')))->value('id'),
            'offer_version_id' => null,
            'parent_run_id' => null,
            'account_snapshot' => ['id' => 1, 'name' => 'Acme', 'website' => 'https://example.com', 'domain_normalized' => 'example.com', 'tags' => [], 'source' => 'manual'],
            'policy_snapshot' => ['source_policy_hash' => hash('sha256', 'native'), 's1_observation_handles' => []],
            'capability_snapshot' => ['selected' => ['research.extract', 'research.search', 'llm.structured_generate']],
            'freshness_policy' => ['snapshot_max_age_hours' => 168, 'claim_max_age_hours' => 720],
            'maximum_cost_micros' => 1_500_000,
            'currency' => 'USD',
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'idempotency_key' => (string) Str::uuid(),
            'orchestration_version' => 1,
            'state' => AccountResearchRunState::Planned,
            'awaiting_reason' => null,
            'safe_failure_code' => null,
            'current_stage' => 'eligibility',
            'estimated_cost_micros' => 100_000,
            'reserved_cost_micros' => 0,
            'actual_cost_micros' => 0,
            'budget_envelope_id' => null,
            'research_budget_run_id' => null,
            'qualification_budget_run_id' => null,
            'initiated_by_user_id' => null,
            'queued_at' => now(),
            'started_at' => null,
            'finished_at' => null,
            'cancelled_at' => null,
        ];
    }
}
