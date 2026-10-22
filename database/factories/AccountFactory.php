<?php

namespace Database\Factories;

use App\Domain\People\Support\DomainNormalizer;
use App\Models\Account;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    public function definition(): array
    {
        $domain = fake()->unique()->domainName();

        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->company(),
            'website' => 'https://'.$domain,
            'domain_normalized' => DomainNormalizer::normalize($domain),
            'notes' => null,
            'tags' => [],
            'source' => Account::SOURCE_MANUAL,
            'created_by_user_id' => null,
        ];
    }
}
