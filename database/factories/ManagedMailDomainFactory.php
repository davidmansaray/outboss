<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailDomainKind;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use App\Models\ManagedMailDomain;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagedMailDomain>
 */
class ManagedMailDomainFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Creating the owner first lets the default workspace derive from
            // the same owner, while callers can override both IDs explicitly.
            'created_by_user_id' => User::factory(),
            'workspace_id' => fn (array $attributes) => User::query()
                ->whereKey($attributes['created_by_user_id'])
                ->firstOrFail()
                ->currentWorkspace()
                ->getKey(),
            'provider' => MailProviderName::Stalwart,
            'managed_instance_key' => 'internal',
            'domain' => fake()->unique()->domainName(),
            'organisational_domain' => fn (array $attributes) => $attributes['domain'],
            'domain_kind' => ManagedMailDomainKind::Root,
            'remote_domain_id' => fn (array $attributes) => 'fake-domain-'.fake()->unique()->numerify('######'),
            'status' => ManagedMailDomainStatus::Active,
            'dns_records' => [
                'mx' => ['host' => 'example.test', 'value' => 'mx.example.test'],
            ],
            'registered_at' => now()->subDays(30),
            'dns_activated_at' => now()->subDays(29),
            'verified_at' => now()->subDays(28),
            'last_verified_at' => now()->subDay(),
            'last_error_code' => null,
            'last_error_message' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ManagedMailDomainStatus::Draft,
            'remote_domain_id' => null,
            'dns_records' => null,
            'verified_at' => null,
            'last_verified_at' => null,
        ]);
    }

    public function subdomain(?string $domain = null): static
    {
        return $this->state(function (array $attributes) use ($domain): array {
            $domain ??= 'outreach.'.($attributes['organisational_domain'] ?? 'example.com');

            return [
                'domain' => $domain,
                'organisational_domain' => substr($domain, strpos($domain, '.') + 1),
                'domain_kind' => ManagedMailDomainKind::Subdomain,
            ];
        });
    }
}
