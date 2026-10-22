<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Exceptions\FrozenMailExperimentInputMutation;
use App\Models\Campaign;
use App\Models\MailExperiment;
use App\Models\MailExperimentCohort;
use App\Models\SequenceStep;
use App\Models\SequenceVersion;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shared guard for mutable campaign inputs owned by a mail experiment.
 *
 * Frozen state belongs to the experiment, not to whichever campaign or
 * sequence model happened to be loaded by a caller. Every entry point first
 * resolves both sides of the cohort/campaign link and fails closed on an
 * invalid or ambiguous link. Lifecycle code may use the narrowly scoped
 * trusted-mutation context for status/version bookkeeping after it has passed
 * the lifecycle's own frozen-input checks.
 */
final class FrozenMailExperimentInputGuard
{
    public const MESSAGE = 'A frozen mail experiment cannot change its inputs.';

    private static int $trustedLifecycleMutationDepth = 0;

    public function assertCampaignMutable(Campaign $campaign): void
    {
        $this->assertNoFrozenExperiment($this->linkedExperiments($campaign));
    }

    public function assertSequenceMutable(Campaign|SequenceVersion|SequenceStep $subject): void
    {
        if ($subject instanceof Campaign) {
            $campaign = $subject;
        } else {
            $version = $subject instanceof SequenceVersion
                ? $subject
                : $subject->version;
            if (! $version instanceof SequenceVersion) {
                throw new FrozenMailExperimentInputMutation('A sequence input references an unknown version.');
            }

            $campaign = $this->campaignForVersion($version);
        }

        if ($campaign instanceof Campaign) {
            $this->assertCampaignMutable($campaign);
        }
    }

    public function assertCohortMutable(MailExperimentCohort $cohort): void
    {
        $experiment = $cohort->relationLoaded('experiment')
            ? $cohort->getRelation('experiment')
            : $cohort->experiment()->first();

        if (! $experiment instanceof MailExperiment) {
            throw new FrozenMailExperimentInputMutation('A cohort has an invalid mail experiment link.');
        }

        if ($experiment->isFrozen()) {
            throw new FrozenMailExperimentInputMutation(self::MESSAGE);
        }
    }

    public function assertCampaignMailboxMutable(Campaign|int $campaign): void
    {
        $resolved = $campaign instanceof Campaign
            ? $campaign
            : Campaign::query()->whereKey($campaign)->first();

        if (! $resolved instanceof Campaign) {
            throw new FrozenMailExperimentInputMutation('A campaign mailbox relation references an unknown campaign.');
        }

        $this->assertCampaignMutable($resolved);
    }

    /**
     * Recipient audience rows are part of the frozen experiment input even
     * though they are not campaign configuration.  Keep this check separate
     * from assertCampaignMutable so ordinary recipient lifecycle updates
     * (pause, resume, outcomes) remain available after launch while new
     * audience rows are refused.
     *
     * A valid running or paused experiment is always frozen.  Treat those
     * states as immutable even when a legacy/malformed row has lost its
     * frozen_at timestamp; accepting an audience mutation in that state would
     * be a fail-open path around the persisted assignment snapshot.
     */
    public function assertCampaignAudienceMutable(Campaign|int $campaign): void
    {
        $resolved = $campaign instanceof Campaign
            ? $campaign
            : Campaign::query()->whereKey($campaign)->first();

        if (! $resolved instanceof Campaign) {
            throw new FrozenMailExperimentInputMutation('A recipient references an unknown campaign.');
        }

        foreach ($this->linkedExperiments($resolved) as $experiment) {
            $status = $experiment->getAttribute('status');
            $status = $status instanceof \BackedEnum
                ? (string) $status->value
                : (string) $status;

            if ($experiment->isFrozen()
                || $experiment->getAttribute('assignment_snapshot_hash') !== null
                || in_array($status, ['running', 'paused', 'completed', 'aborted'], true)) {
                throw new FrozenMailExperimentInputMutation(self::MESSAGE);
            }
        }
    }

    /**
     * Lifecycle services change status/version pointers as part of an
     * already-validated transition. Keep that exception scoped to the exact
     * callback so ordinary model saves cannot opt out of the guard.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runTrustedLifecycleMutation(callable $callback): mixed
    {
        self::$trustedLifecycleMutationDepth++;

        try {
            return $callback();
        } finally {
            self::$trustedLifecycleMutationDepth--;
        }
    }

    public static function lifecycleMutationIsTrusted(): bool
    {
        return self::$trustedLifecycleMutationDepth > 0;
    }

    /**
     * Resolve every cohort that claims the campaign, including a direct
     * campaign foreign key and the reverse cohort campaign key. A malformed
     * link is unsafe even when the malformed experiment is not frozen.
     *
     * @return Collection<int, MailExperiment>
     */
    private function linkedExperiments(Campaign $campaign): Collection
    {
        $cohortId = $campaign->getAttribute('mail_experiment_cohort_id');
        if (! is_numeric($campaign->getKey())) {
            if ($cohortId !== null) {
                throw new FrozenMailExperimentInputMutation('A campaign has an invalid mail experiment link.');
            }

            return new Collection;
        }

        if ($cohortId !== null && ! is_numeric($cohortId)) {
            throw new FrozenMailExperimentInputMutation('A campaign has an invalid mail experiment link.');
        }

        $cohorts = MailExperimentCohort::query()
            ->where(function ($query) use ($campaign, $cohortId): void {
                $query->where('campaign_id', $campaign->getKey());
                if (is_numeric($cohortId)) {
                    $query->orWhere('id', (int) $cohortId);
                }
            })
            ->with('experiment')
            ->get();

        $experiments = new Collection;
        foreach ($cohorts as $cohort) {
            $experiment = $cohort->getRelation('experiment');
            if (! $experiment instanceof MailExperiment
                || (int) $cohort->getAttribute('campaign_id') !== (int) $campaign->getKey()
                || (int) $experiment->getAttribute('workspace_id') !== (int) $campaign->getAttribute('workspace_id')) {
                throw new FrozenMailExperimentInputMutation('A campaign has an invalid mail experiment link.');
            }

            $experiments->push($experiment);
        }

        return $experiments->unique(fn (MailExperiment $experiment): int => (int) $experiment->getKey())->values();
    }

    private function campaignForVersion(SequenceVersion $version): ?Campaign
    {
        $campaign = $version->relationLoaded('campaign')
            ? $version->getRelation('campaign')
            : $version->campaign()->first();

        if ($campaign instanceof Campaign) {
            return $campaign;
        }

        if ($version->getAttribute('campaign_id') !== null) {
            throw new FrozenMailExperimentInputMutation('A sequence version references an unknown campaign.');
        }

        return null;
    }

    /** @param Collection<int, MailExperiment> $experiments */
    private function assertNoFrozenExperiment(Collection $experiments): void
    {
        foreach ($experiments as $experiment) {
            if ($experiment->isFrozen()) {
                throw new FrozenMailExperimentInputMutation(self::MESSAGE);
            }
        }
    }
}
