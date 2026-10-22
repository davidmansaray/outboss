<?php

namespace Database\Factories;

use App\Domain\Conversions\Enums\ConversionConnectionKind;
use App\Domain\Conversions\Enums\ConversionConnectionStatus;
use App\Models\ConversionSourceConnection;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConversionSourceConnection> */
class ConversionSourceConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory(),
            'public_id' => (string) Str::uuid(),
            'kind' => ConversionConnectionKind::Webhook,
            'display_name' => 'Fixture webhook',
            'status' => ConversionConnectionStatus::Active,
            'current_version_id' => null,
            'lock_version' => 0,
            'last_used_at' => null,
            'disabled_at' => null,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }
}
