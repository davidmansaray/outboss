<?php

namespace Database\Factories;

use App\Domain\Integrations\Enums\IntegrationConnectionStatus;
use App\Models\IntegrationConnection;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IntegrationConnection> */
class IntegrationConnectionFactory extends Factory
{
    protected $model = IntegrationConnection::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'provider' => 'fake',
            'status' => IntegrationConnectionStatus::Healthy,
            'active_credential_version_id' => null,
            'last_tested_at' => now(),
            'last_healthy_at' => now(),
            'retry_after_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }
}
