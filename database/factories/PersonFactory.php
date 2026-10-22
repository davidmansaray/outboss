<?php

namespace Database\Factories;

use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\Account;
use App\Models\Person;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->name();
        $email = fake()->unique()->safeEmail();

        return [
            'workspace_id' => Workspace::factory(),
            'account_id' => null,
            'full_name' => $name,
            'first_name' => EmailNormalizer::firstName($name),
            'email_address' => $email,
            'email_normalized' => EmailNormalizer::normalize($email),
            'job_title' => null,
            'notes' => null,
            'tags' => [],
            'source' => Person::SOURCE_MANUAL,
            'created_by_user_id' => null,
        ];
    }

    public function forAccount(Account $account): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $account->workspace_id,
            'account_id' => $account->id,
        ]);
    }
}
