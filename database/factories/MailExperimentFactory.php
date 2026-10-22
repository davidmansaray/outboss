<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\MailExperimentStatus;
use App\Models\MailExperiment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MailExperiment>
 */
class MailExperimentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'created_by_user_id' => User::factory(),
            'workspace_id' => fn (array $attributes): int => (int) User::query()
                ->whereKey($attributes['created_by_user_id'])
                ->firstOrFail()
                ->currentWorkspace()
                ->getKey(),
            'name' => fake()->words(3, true),
            'hypothesis' => 'A dedicated domain strategy will produce a comparable reply rate.',
            'status' => MailExperimentStatus::Draft,
            'randomisation_seed' => Str::random(48),
            'primary_metric' => 'human_reply_rate',
            'target_assignments_per_cohort' => null,
            'configuration' => [],
            'operator_minutes' => null,
            'operator_cost_micros' => null,
            'infrastructure_cost_micros' => null,
            'incident_count' => 0,
            'incidents' => [],
            'assignment_snapshot' => null,
            'assignment_snapshot_hash' => null,
        ];
    }

    public function forWorkspace(int $workspaceId, int $userId): static
    {
        return $this->state([
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $userId,
        ]);
    }

    public function ready(): static
    {
        return $this->state(['status' => MailExperimentStatus::Ready]);
    }

    public function running(): static
    {
        return $this->state([
            'status' => MailExperimentStatus::Running,
            'frozen_at' => now(),
            'started_at' => now(),
        ]);
    }
}
