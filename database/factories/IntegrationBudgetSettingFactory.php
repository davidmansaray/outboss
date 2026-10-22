<?php

namespace Database\Factories;

use App\Models\IntegrationBudgetSetting;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IntegrationBudgetSetting> */
class IntegrationBudgetSettingFactory extends Factory
{
    protected $model = IntegrationBudgetSetting::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'currency' => 'USD',
            'monthly_limit_micros' => 50_000_000,
            'version' => 1,
            'updated_by_user_id' => null,
        ];
    }
}
