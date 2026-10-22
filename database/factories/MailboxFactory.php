<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\ConnectionStatus;
use App\Models\Mailbox;
use App\Models\ProviderConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mailbox>
 */
class MailboxFactory extends Factory
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
            'provider_connection_id' => fn (array $attributes) => ProviderConnection::factory()->create([
                'user_id' => $attributes['user_id'],
                'workspace_id' => $attributes['workspace_id'],
            ]),
            'provider_mailbox_id' => fake()->uuid(),
            'email_address' => fake()->unique()->safeEmail(),
            'display_name' => fake()->name(),
            'status' => ConnectionStatus::Connected,
            'provider_metadata' => [],
        ];
    }
}
