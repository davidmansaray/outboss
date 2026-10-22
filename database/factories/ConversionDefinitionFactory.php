<?php

namespace Database\Factories;

use App\Domain\Conversions\Enums\ConversionDefinitionStatus;
use App\Models\ConversionDefinition;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConversionDefinition> */
class ConversionDefinitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory(),
            'event_key' => fake()->unique()->slug(2, '_'),
            'current_version_id' => null,
            'status' => ConversionDefinitionStatus::Draft,
            'lock_version' => 0,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }
}
