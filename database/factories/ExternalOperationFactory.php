<?php

namespace Database\Factories;

use App\Domain\Integrations\Enums\IntegrationOperationStatus;
use App\Models\ExternalOperation;
use App\Models\IntegrationConnection;
use App\Models\IntegrationCredentialVersion;
use App\Models\IntegrationProviderRegistration;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ExternalOperation> */
class ExternalOperationFactory extends Factory
{
    protected $model = ExternalOperation::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory(),
            'budget_period_id' => null,
            'source_run_id' => null,
            'registration_id' => null,
            'connection_id' => null,
            'credential_version_id' => null,
            'provider' => 'fake',
            'capability' => 'account.discover',
            'operation' => 'discover',
            'idempotency_key' => fake()->unique()->bothify('external-????????'),
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'capability_schema_version' => 1,
            'pricing_version' => 1,
            'policy_version' => 1,
            'endpoint' => 'fake/account.discover',
            'status' => IntegrationOperationStatus::Reserved,
            'native_unit' => 'requests',
            'reserved_native_units' => 1,
            'reserved_cost_micros' => 1_000,
            'actual_native_units' => null,
            'actual_cost_micros' => null,
            'cost_basis' => null,
            'provider_request_id' => null,
            'provider_snapshot_id' => null,
            'safe_request_id' => null,
            'safe_metadata' => [],
            'result_reference' => null,
            'initiated_by_user_id' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'retry_after_at' => null,
            'safe_error_code' => null,
            'safe_error_message' => null,
            'reserved_at' => now(),
            'started_at' => null,
            'finished_at' => null,
            'reconcile_after' => null,
            'finalize_after' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ExternalOperation $operation): void {
            if ($operation->getAttribute('registration_id') === null) {
                $connection = IntegrationConnection::factory()->create([
                    'workspace_id' => $operation->workspace_id,
                    'provider' => $operation->provider,
                ]);
                $registration = IntegrationProviderRegistration::factory()->create([
                    'workspace_id' => $operation->workspace_id,
                    'connection_id' => $connection->id,
                    'provider' => $operation->provider,
                    'capability' => $operation->capability,
                ]);
                $operation->setAttribute('connection_id', $connection->id);
                $operation->setAttribute('registration_id', $registration->id);
            }
            if ($operation->getAttribute('credential_version_id') === null) {
                $credential = IntegrationCredentialVersion::factory()->create([
                    'workspace_id' => $operation->workspace_id,
                    'connection_id' => $operation->connection_id,
                ]);
                $operation->setAttribute('credential_version_id', $credential->id);
            }
        });
    }
}
