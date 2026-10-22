<?php

namespace Database\Factories;

use App\Domain\Prospecting\Enums\MembershipState;
use App\Models\Account;
use App\Models\AccountSourceMembership;
use App\Models\ProspectSource;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AccountSourceMembership> */
class AccountSourceMembershipFactory extends Factory
{
    public function definition(): array
    {
        $observedAt = now();

        return [
            'public_id' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory(),
            'prospect_source_id' => ProspectSource::factory(),
            'account_id' => Account::factory(),
            'first_source_run_id' => SourceRun::factory(),
            'first_source_observation_id' => SourceObservation::factory(),
            'last_source_run_id' => SourceRun::factory(),
            'last_source_observation_id' => SourceObservation::factory(),
            'state' => MembershipState::Active,
            'first_observed_at' => $observedAt,
            'last_observed_at' => $observedAt,
            'expired_at' => null,
        ];
    }

    public function forObservation(SourceObservation $observation, Account $account): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $observation->workspace_id,
            'prospect_source_id' => $observation->prospect_source_id,
            'account_id' => $account->id,
            'first_source_run_id' => $observation->source_run_id,
            'first_source_observation_id' => $observation->id,
            'last_source_run_id' => $observation->source_run_id,
            'last_source_observation_id' => $observation->id,
        ]);
    }
}
