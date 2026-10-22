<?php

namespace Database\Factories;

use App\Models\IntegrationCapabilityRoute;
use App\Models\IntegrationProviderRegistration;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IntegrationCapabilityRoute> */
class IntegrationCapabilityRouteFactory extends Factory
{
    protected $model = IntegrationCapabilityRoute::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'capability' => 'account.discover',
            'active_registration_id' => null,
            'status' => 'active',
            'version' => 1,
            'updated_by_user_id' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (IntegrationCapabilityRoute $route): void {
            if ($route->active_registration_id !== null) {
                return;
            }
            $registration = IntegrationProviderRegistration::factory()->create([
                'workspace_id' => $route->workspace_id,
                'capability' => $route->capability,
            ]);
            $route->active_registration_id = $registration->id;
        });
    }
}
