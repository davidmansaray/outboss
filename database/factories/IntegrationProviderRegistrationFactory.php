<?php

namespace Database\Factories;

use App\Domain\Integrations\Support\CapabilityCatalogue;
use App\Models\IntegrationConnection;
use App\Models\IntegrationProviderRegistration;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IntegrationProviderRegistration> */
class IntegrationProviderRegistrationFactory extends Factory
{
    protected $model = IntegrationProviderRegistration::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'connection_id' => null,
            'provider' => 'fake',
            'adapter_version' => 'fake-1',
            'capability' => 'account.discover',
            'capability_schema_version' => 1,
            'pricing_version' => 1,
            'policy_version' => 1,
            'code_definition_hash' => app(CapabilityCatalogue::class)->definitionHash('account.discover'),
            'coverage' => [],
            'limits' => [],
            'enabled' => true,
            'enabled_at' => now(),
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (IntegrationProviderRegistration $registration): void {
            if ($registration->getAttribute('connection_id') !== null) {
                return;
            }
            $connection = IntegrationConnection::factory()->create([
                'workspace_id' => $registration->workspace_id,
                'provider' => $registration->provider,
            ]);
            $registration->setAttribute('connection_id', $connection->id);
        });
    }
}
