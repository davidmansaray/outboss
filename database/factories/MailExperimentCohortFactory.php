<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\MailExperimentDomainStrategy;
use App\Models\MailExperiment;
use App\Models\MailExperimentCohort;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailExperimentCohort>
 */
class MailExperimentCohortFactory extends Factory
{
    public function definition(): array
    {
        return [
            'mail_experiment_id' => MailExperiment::factory(),
            'key' => fake()->unique()->randomElement(['a', 'b', 'control', 'treatment']),
            'label' => fake()->words(2, true),
            'domain_strategy' => MailExperimentDomainStrategy::Subdomain,
            'configuration' => [],
            'snapshot_hash' => null,
            'mailbox_topology_snapshot' => null,
            'campaign_id' => null,
        ];
    }

    public function separateRoots(): static
    {
        return $this->state(['domain_strategy' => MailExperimentDomainStrategy::SeparateRootDomains]);
    }
}
