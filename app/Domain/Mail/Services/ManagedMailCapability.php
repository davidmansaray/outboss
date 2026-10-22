<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Enums\MailExperimentStatus;
use App\Models\Campaign;
use App\Models\MailExperiment;
use App\Models\MailExperimentCohort;
use App\Models\User;
use App\Models\Workspace;

/**
 * Central policy input for internal managed mail and experiment surfaces.
 *
 * During prelaunch, verified workspace owners are the rollout boundary.
 */
final class ManagedMailCapability
{
    public const MANAGED_MAIL = 'managed_mail';

    public const MAIL_EXPERIMENTS = 'mail_experiments';

    /**
     * A cohort campaign is an experiment send only while its persisted
     * experiment is running. A queued worker must not rely on the status
     * captured when the job was dispatched.
     */
    public function experimentRuntimeAllows(MailExperiment $experiment): bool
    {
        $status = $experiment->getAttribute('status');
        $isRunning = $status instanceof MailExperimentStatus
            ? $status === MailExperimentStatus::Running
            : (string) $status === MailExperimentStatus::Running->value;

        return $isRunning;
    }

    /**
     * Resolve an experiment link from the database on every call. This keeps
     * both the scheduler and a worker handling an old queued job subject to
     * the current experiment status.
     *
     * @phpstan-impure
     */
    public function campaignRuntimeAllows(?Campaign $campaign): bool
    {
        if (! $campaign instanceof Campaign) {
            return true;
        }

        $cohortId = $campaign->getAttribute('mail_experiment_cohort_id');
        $cohorts = MailExperimentCohort::query()
            ->with('experiment')
            ->where(function ($query) use ($campaign, $cohortId): void {
                $query->where('campaign_id', $campaign->getKey());
                if (is_numeric($cohortId)) {
                    $query->orWhere('id', (int) $cohortId);
                }
            })
            ->get();

        if ($cohorts->isEmpty()) {
            return $cohortId === null;
        }

        // A campaign may be referenced by at most one cohort for a valid
        // experiment. Treat an ambiguous legacy link as an unsafe state
        // rather than selecting whichever row the database returns first.
        if ($cohorts->count() !== 1) {
            return false;
        }

        $cohort = $cohorts->first();
        $experiment = $cohort->experiment;

        if (! $experiment instanceof MailExperiment) {
            return false;
        }

        if ((int) $cohort->getAttribute('campaign_id') !== (int) $campaign->getKey()
            || (int) $cohort->getAttribute('mail_experiment_id') !== (int) $experiment->getKey()
            || (int) $campaign->getAttribute('workspace_id') !== (int) $experiment->getAttribute('workspace_id')) {
            return false;
        }

        return $this->experimentRuntimeAllows($experiment);
    }

    public function allows(
        User $user,
        Workspace $workspace,
        string $capability = self::MANAGED_MAIL,
        bool $ownerRequired = true,
    ): bool {
        if ($user->email_verified_at === null) {
            return false;
        }

        if (! $user->workspaces()->whereKey($workspace->id)->exists()) {
            return false;
        }

        if ($ownerRequired && ! $workspace->isOwner($user)) {
            return false;
        }

        return true;
    }
}
