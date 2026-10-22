<?php

namespace Database\Factories;

use App\Domain\Conversions\Enums\ConversionCredentialStatus;
use App\Models\ConversionSourceConnection;
use App\Models\ConversionSourceConnectionVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConversionSourceConnectionVersion> */
class ConversionSourceConnectionVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => fn (array $attributes): int => (int) ConversionSourceConnection::query()->whereKey($attributes['connection_id'])->value('workspace_id'),
            'connection_id' => ConversionSourceConnection::factory(),
            'version' => 1,
            'status' => ConversionCredentialStatus::Active,
            'encrypted_secret' => 'fixture-secret',
            'allowed_conversion_definition_ids' => [],
            'allowed_source_types' => ['webhook'],
            'signature_algorithm' => 'hmac_sha256',
            'payload_limit_bytes' => 262144,
            'replay_window_seconds' => 300,
            'valid_from' => now(),
            'overlap_until' => null,
            'ip_policy' => null,
            'rate_policy' => null,
            'created_by_user_id' => null,
            'created_at' => now(),
            'retired_at' => null,
            'wiped_at' => null,
        ];
    }
}
