<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\RecipientMailboxProvider;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\MailExperiment;
use App\Models\MailExperimentAssignment;
use App\Models\MailExperimentCohort;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MailExperimentAssignment>
 */
class MailExperimentAssignmentFactory extends Factory
{
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'mail_experiment_cohort_id' => MailExperimentCohort::factory(),
            'mail_experiment_id' => fn (array $attributes): int => (int) MailExperimentCohort::query()
                ->whereKey($attributes['mail_experiment_cohort_id'])
                ->value('mail_experiment_id'),
            'workspace_id' => fn (array $attributes): int => (int) MailExperimentCohort::query()
                ->join('mail_experiments', 'mail_experiments.id', '=', 'mail_experiment_cohorts.mail_experiment_id')
                ->where('mail_experiment_cohorts.id', $attributes['mail_experiment_cohort_id'])
                ->value('mail_experiments.workspace_id'),
            'person_id' => null,
            'account_id' => null,
            'campaign_id' => null,
            'campaign_recipient_id' => null,
            'email_normalized' => EmailNormalizer::normalize($email),
            'recipient_domain' => EmailNormalizer::domain($email),
            'recipient_provider' => RecipientMailboxProvider::Unknown,
            'stratum_key' => RecipientMailboxProvider::Unknown->value,
            'assignment_key' => hash('sha256', Str::uuid()->toString()),
            'provider_mx_snapshot' => [],
            'assignment_snapshot_hash' => null,
            'assigned_at' => now(),
            'exclusion_reason' => null,
        ];
    }

    public function excluded(string $reason = 'ineligible'): static
    {
        return $this->state([
            'mail_experiment_cohort_id' => null,
            'mail_experiment_id' => MailExperiment::factory(),
            'workspace_id' => fn (array $attributes): int => (int) MailExperiment::query()
                ->whereKey($attributes['mail_experiment_id'])
                ->value('workspace_id'),
            'exclusion_reason' => $reason,
        ]);
    }
}
