<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceClientOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceClientOperation>
 */
class WorkspaceClientOperationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'initiating_user_id' => User::factory(),
            'client_operation_id' => fake()->uuid(),
            'server_fingerprint' => hash('sha256', fake()->uuid()),
            'resulting_workspace_id' => Workspace::factory(),
            'redirect_route' => 'settings.gtm-profile',
            'redirect_parameters' => [],
            'response_metadata' => [],
        ];
    }
}
