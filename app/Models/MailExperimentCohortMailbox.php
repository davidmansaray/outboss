<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Pivot row for an experiment cohort's sender mailbox set.
 *
 * The denormalised experiment ID is populated for ordinary Eloquent
 * attach/sync calls as well as the lifecycle service, allowing the unique
 * database index to enforce one cohort per mailbox inside an experiment.
 */
class MailExperimentCohortMailbox extends Pivot
{
    public $incrementing = false;

    protected $table = 'mail_experiment_cohort_mailbox';

    protected static function booted(): void
    {
        // BelongsToMany::attach/sync use this custom pivot class.  Guarding
        // saving/deleting here closes the relation API path that would
        // otherwise bypass MailExperimentLifecycleService::attachMailboxes.
        static::saving(function (self $pivot): void {
            $pivot->assertMutableAndConsistent();
        });

        static::deleting(function (self $pivot): void {
            $pivot->assertMutableAndConsistent();
        });
    }

    private function assertMutableAndConsistent(): void
    {
        $cohortId = $this->getAttribute('mail_experiment_cohort_id');
        $originalCohortId = $this->getOriginal('mail_experiment_cohort_id');
        $cohortIds = array_values(array_unique(array_filter([
            is_numeric($cohortId) ? (int) $cohortId : null,
            is_numeric($originalCohortId) ? (int) $originalCohortId : null,
        ], static fn (?int $id): bool => $id !== null && $id > 0)));

        if ($cohortIds === []) {
            throw new LogicException('A cohort mailbox pivot must reference a cohort.');
        }

        $experimentIds = DB::table('mail_experiment_cohorts')
            ->whereIn('id', $cohortIds)
            ->pluck('mail_experiment_id', 'id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($cohortIds as $id) {
            $experimentId = $experimentIds[$id] ?? null;
            if ($experimentId === null) {
                throw new LogicException('A cohort mailbox pivot references an unknown cohort.');
            }

            if (DB::table('mail_experiments')
                ->where('id', $experimentId)
                ->whereNotNull('frozen_at')
                ->exists()) {
                throw new LogicException('A frozen mail experiment cohort mailbox topology is immutable.');
            }
        }

        $cohortExperimentId = $experimentIds[(int) $cohortId] ?? null;
        if ($cohortExperimentId === null) {
            throw new LogicException('A cohort mailbox pivot references an unknown cohort.');
        }

        $suppliedExperimentId = $this->getAttribute('mail_experiment_id');
        if ($suppliedExperimentId === null) {
            $this->setAttribute('mail_experiment_id', $cohortExperimentId);
        } elseif ((int) $suppliedExperimentId !== $cohortExperimentId) {
            throw new LogicException('The cohort mailbox experiment does not match its cohort.');
        }
    }
}
