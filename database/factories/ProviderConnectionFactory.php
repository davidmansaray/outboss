<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\AuthScheme;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailProviderName;
use App\Models\ProviderConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderConnection>
 */
class ProviderConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => fn (array $attributes) => User::query()
                ->whereKey($attributes['user_id'])
                ->firstOrFail()
                ->currentWorkspace()
                ->getKey(),
            'provider' => MailProviderName::Gmail,
            'provider_user_id' => fake()->uuid(),
            'connection_kind' => ConnectionKind::OAuth,
            'auth_scheme' => AuthScheme::OAuth2,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'credential_payload' => null,
            'managed_instance_key' => null,
            'token_expires_at' => now()->addHour(),
            'token_version' => 0,
            'authorization_version' => 1,
            'granted_scopes' => ['gmail.readonly', 'gmail.send'],
            'provider_metadata' => [],
            'status' => ConnectionStatus::Connected,
        ];
    }
}
