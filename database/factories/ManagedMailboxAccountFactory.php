<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagedMailboxAccount>
 */
class ManagedMailboxAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'managed_mail_domain_id' => ManagedMailDomain::factory(),
            'mailbox_id' => null,
            'remote_account_id' => fn () => 'fake-account-'.fake()->unique()->numerify('######'),
            'local_part' => fn () => fake()->unique()->userName(),
            'display_name' => fn () => fake()->name(),
            'credential_version' => 1,
            'status' => ManagedMailboxAccountStatus::Active,
            'provisioned_at' => now()->subDays(2),
            'last_verified_at' => now()->subDay(),
            'last_error_code' => null,
            'last_error_message' => null,
        ];
    }

    public function provisioning(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ManagedMailboxAccountStatus::Provisioning,
            'provisioned_at' => null,
            'last_verified_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ManagedMailboxAccountStatus::Suspended,
        ]);
    }
}
