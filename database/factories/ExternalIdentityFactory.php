<?php

namespace Database\Factories;

use App\Domain\Prospecting\Enums\ExternalIdentityState;
use App\Models\Account;
use App\Models\ExternalIdentity;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ExternalIdentity> */
class ExternalIdentityFactory extends Factory
{
    public function definition(): array
    {
        $value = 'external-'.Str::lower(Str::random(12));

        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory(),
            'entity_type' => 'account',
            'account_id' => Account::factory(),
            'person_id' => null,
            'provider' => 'fixture',
            'identity_type' => 'provider_record_id',
            'value' => $value,
            'value_hash' => hash('sha256', $value),
            'first_source_observation_id' => null,
            'last_source_observation_id' => null,
            'first_observed_at' => now(),
            'last_observed_at' => now(),
            'state' => ExternalIdentityState::Active,
            'policy_hash' => null,
            'retain_until' => now()->addYear(),
        ];
    }

    public function forPerson(int $personId): static
    {
        return $this->state(fn (): array => [
            'entity_type' => 'person',
            'account_id' => null,
            'person_id' => $personId,
        ]);
    }
}
