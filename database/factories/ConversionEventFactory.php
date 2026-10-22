<?php

namespace Database\Factories;

use App\Models\ConversionDefinitionVersion;
use App\Models\ConversionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConversionEvent> */
class ConversionEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => fn (array $attributes): int => (int) ConversionDefinitionVersion::query()->whereKey($attributes['conversion_definition_version_id'])->value('workspace_id'),
            'conversion_definition_id' => fn (array $attributes): string => (string) ConversionDefinitionVersion::query()->whereKey($attributes['conversion_definition_version_id'])->value('conversion_definition_id'),
            'conversion_definition_version_id' => ConversionDefinitionVersion::factory(),
            'receipt_id' => null,
            'source_namespace' => 'fixture',
            'source_type' => 'manual',
            'external_event_id' => fake()->uuid(),
            'properties' => [],
            'identity_handles' => [],
            'occurred_at' => now(),
            'received_at' => now(),
            'value' => null,
            'currency' => null,
            'verification_state' => 'not_required',
            'state' => 'received',
            'matched_account_id' => null,
            'lineage_root_id' => null,
            'corrects_event_id' => null,
            'reverses_event_id' => null,
            'safe_rejection_code' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
