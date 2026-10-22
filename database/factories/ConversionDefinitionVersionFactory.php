<?php

namespace Database\Factories;

use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConversionDefinitionVersion> */
class ConversionDefinitionVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => fn (array $attributes): int => (int) ConversionDefinition::query()->whereKey($attributes['conversion_definition_id'])->value('workspace_id'),
            'conversion_definition_id' => ConversionDefinition::factory(),
            'version' => 1,
            'schema_version' => ConversionDefinitionVersion::SCHEMA_VERSION,
            'display_name' => 'Fixture conversion',
            'description' => null,
            'subject_type' => 'Account',
            'allowed_sources' => ['manual', 'api', 'webhook'],
            'property_schema' => ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false],
            'allowed_identity_keys' => ['account_id', 'external_identity', 'domain'],
            'dedupe_strategy' => 'source_and_external_id',
            'dedupe_configuration' => [],
            'attribution_window' => ['days' => 30],
            'value_policy' => ['allowed' => false, 'currency' => null],
            'correction_policy' => ['allowed' => true],
            'reversal_policy' => ['allowed' => true],
            'retention_policy' => ['unmatched_identity_days' => 30],
            'export_policy' => ['allowed' => true],
            'content_hash' => str_repeat('0', 64),
            'origin' => 'fixture',
            'actor_type' => 'system',
            'actor_user_id' => null,
            'actor_display_name' => 'Fixture',
            'created_at' => now(),
        ];
    }
}
