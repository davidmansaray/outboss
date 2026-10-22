<?php

namespace Database\Factories;

use App\Domain\Integrations\Enums\IntegrationCredentialStatus;
use App\Models\IntegrationConnection;
use App\Models\IntegrationCredentialVersion;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IntegrationCredentialVersion> */
class IntegrationCredentialVersionFactory extends Factory
{
    protected $model = IntegrationCredentialVersion::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'connection_id' => null,
            'version' => 1,
            'encrypted_secret' => 'fake-integration-secret',
            'status' => IntegrationCredentialStatus::Active,
            'created_by_user_id' => null,
            'tested_at' => now(),
            'activated_at' => now(),
            'retire_after' => null,
            'wiped_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (IntegrationCredentialVersion $version): void {
            if ($version->getAttribute('connection_id') !== null) {
                return;
            }
            $connection = IntegrationConnection::factory()->create([
                'workspace_id' => $version->workspace_id,
            ]);
            $version->setAttribute('connection_id', $connection->id);
        });
    }
}
