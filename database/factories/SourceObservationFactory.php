<?php

namespace Database\Factories;

use App\Domain\Prospecting\Enums\ObservationDisposition;
use App\Domain\Prospecting\Enums\ObservationEntityType;
use App\Models\ProspectSource;
use App\Models\ProspectSourceVersion;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SourceObservation> */
class SourceObservationFactory extends Factory
{
    public function definition(): array
    {
        $values = ['name' => fake()->company(), 'website' => 'https://'.fake()->domainName()];

        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory(),
            'prospect_source_id' => ProspectSource::factory(),
            'prospect_source_version_id' => ProspectSourceVersion::factory(),
            'source_run_id' => SourceRun::factory(),
            'row_number' => fake()->numberBetween(1, 1000),
            'input_key' => (string) Str::uuid(),
            'observation_key' => hash('sha256', Str::uuid()->toString()),
            'entity_type' => ObservationEntityType::Account,
            'observed_values' => $values,
            'observed_values_hash' => hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)),
            'raw_input_hash' => null,
            'provider_record_id' => null,
            'provider_snapshot_id' => null,
            'canonical_url' => $values['website'],
            'provider_observed_at' => null,
            'retrieved_at' => now(),
            'disposition' => ObservationDisposition::PendingReview,
            'account_id' => null,
            'person_id' => null,
            'match_method' => null,
            'match_confidence' => null,
            'policy_classification' => 'standard',
            'policy_hash' => null,
            'retain_until' => now()->addYear(),
            'display_classification' => 'standard',
            'export_classification' => 'standard',
            'safe_error_code' => null,
            'safe_error_message' => null,
            'expired_at' => null,
            'redacted_at' => null,
        ];
    }

    public function forSourceRun(SourceRun $run): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $run->workspace_id,
            'prospect_source_id' => $run->prospect_source_id,
            'prospect_source_version_id' => $run->prospect_source_version_id,
            'source_run_id' => $run->id,
        ]);
    }

    public function person(): static
    {
        return $this->state(fn (): array => [
            'entity_type' => ObservationEntityType::Person,
            'observed_values' => ['email' => fake()->unique()->safeEmail(), 'full_name' => fake()->name()],
        ]);
    }
}
