<?php

namespace Database\Factories;

use App\Domain\Prospecting\Enums\CanonicalFieldDecision;
use App\Models\Account;
use App\Models\CanonicalFieldProvenance;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CanonicalFieldProvenance> */
class CanonicalFieldProvenanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory(),
            'entity_type' => 'account',
            'account_id' => Account::factory(),
            'person_id' => null,
            'field_path' => 'name',
            'source_observation_id' => SourceObservation::factory(),
            'source_run_id' => SourceRun::factory(),
            'decision' => CanonicalFieldDecision::Applied,
            'prior_value_hash' => null,
            'new_value_hash' => hash('sha256', fake()->company()),
            'actor_type' => 'system',
            'actor_user_id' => null,
            'actor_display_name' => 'System',
            'created_at' => now(),
        ];
    }
}
