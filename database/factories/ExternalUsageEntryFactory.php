<?php

namespace Database\Factories;

use App\Domain\Integrations\Enums\ExternalUsageEntryType;
use App\Models\ExternalOperation;
use App\Models\ExternalUsageEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExternalUsageEntry> */
class ExternalUsageEntryFactory extends Factory
{
    protected $model = ExternalUsageEntry::class;

    public function definition(): array
    {
        return [
            'workspace_id' => null,
            'operation_id' => ExternalOperation::factory(),
            'budget_period_id' => null,
            'registration_id' => null,
            'provider' => 'fake',
            'capability' => 'account.discover',
            'operation' => 'discover',
            'endpoint' => 'fake/account.discover',
            'entry_type' => ExternalUsageEntryType::Charge,
            'native_unit' => 'requests',
            'native_units' => 1,
            'raw_provider_cost' => null,
            'cost_micros' => 1_000,
            'cost_basis' => 'deterministic_fake',
            'provider_request_id' => null,
            'provider_snapshot_id' => null,
            'occurred_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ExternalUsageEntry $entry): void {
            $operation = $entry->operation_id
                ? ExternalOperation::query()->find($entry->operation_id)
                : null;
            if ($operation !== null) {
                $entry->setAttribute('workspace_id', $entry->getAttribute('workspace_id') ?? $operation->workspace_id);
                $entry->setAttribute('registration_id', $entry->getAttribute('registration_id') ?? $operation->registration_id);
                $entry->setAttribute('budget_period_id', $entry->getAttribute('budget_period_id') ?? $operation->budget_period_id);
                $entry->provider = $operation->provider;
                $entry->capability = $operation->capability;
                $entry->operation = $operation->operation;
                $entry->endpoint = $operation->endpoint;
            }
        });
    }
}
