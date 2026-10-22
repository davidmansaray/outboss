<?php

namespace Database\Factories;

use App\Models\IntegrationBudgetPeriod;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IntegrationBudgetPeriod> */
class IntegrationBudgetPeriodFactory extends Factory
{
    protected $model = IntegrationBudgetPeriod::class;

    public function definition(): array
    {
        $start = now()->startOfMonth();

        return [
            'workspace_id' => Workspace::factory(),
            'period_start_utc' => $start->toDateString(),
            'period_end_utc' => $start->clone()->addMonthNoOverflow()->toDateString(),
            'limit_snapshot_micros' => 50_000_000,
            'held_micros' => 0,
            'committed_micros' => 0,
            'over_limit' => false,
            'budget_breach' => false,
            'settings_version' => 1,
        ];
    }
}
