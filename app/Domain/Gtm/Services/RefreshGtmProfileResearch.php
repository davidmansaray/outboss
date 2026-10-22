<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Gtm\Enums\GtmProfileResearchState;
use App\Domain\Gtm\Enums\GtmResearchRunState;
use App\Domain\Gtm\Support\CanonicalGtmProfilePayload;
use App\Models\AiBudgetRun;
use App\Models\GtmProfile;
use App\Models\GtmProfileClientOperation;
use App\Models\GtmProfileResearchDispatch;
use App\Models\GtmProfileResearchRun;
use App\Models\GtmProfileVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Queue a new or retryable GTM profile research run.
 *
 * This service owns the durable run/dispatch transition only. Provider
 * execution remains the responsibility of the research worker.
 */
final class RefreshGtmProfileResearch
{
    /** @var list<string> */
    private const ACTIONS = ['refresh', 'retry'];

    /** @var list<string> */
    private const BUSY_STATES = ['queued', 'running'];

    /** @var list<string> */
    private const SUPERSEDE_STATES = [
        'queued',
        'running',
        'awaiting_provider',
        'awaiting_budget',
    ];

    /** @var list<string> */
    private const RETRYABLE_STATES = ['failed', 'cancelled', 'awaiting_provider', 'awaiting_budget'];

    public function __construct(
        private readonly AiBudgetGateway $budget,
    ) {}

    public function handle(
        GtmProfile $profile,
        User $actor,
        string $action = 'refresh',
        ?string $clientOperationId = null,
        bool $supersedeBusy = false,
    ): GtmProfileResearchRun {
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages([
                'action' => 'This research action is not supported.',
            ]);
        }

        $clientOperationId = $this->operationId($clientOperationId);

        return DB::transaction(function () use ($profile, $actor, $action, $clientOperationId, $supersedeBusy): GtmProfileResearchRun {
            $lockedProfile = GtmProfile::query()
                ->whereKey($profile->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertOwner($lockedProfile, $actor);

            // Every refresh decision is serialized at workspace scope. The
            // profile lock alone cannot protect the concurrent-run cap when a
            // future workspace has more than one research aggregate.
            Workspace::query()->whereKey($lockedProfile->workspace_id)->lockForUpdate()->firstOrFail();

            $version = $this->currentVersion($lockedProfile);
            $website = $this->website($version);
            if ($website === null) {
                throw ValidationException::withMessages([
                    'website_url' => 'Add a public website before starting research.',
                ]);
            }
            $fingerprint = $this->fingerprint($lockedProfile, $action, $version, $website);

            $operation = GtmProfileClientOperation::query()
                ->where('gtm_profile_id', $lockedProfile->id)
                ->where('client_operation_id', $clientOperationId)
                ->lockForUpdate()
                ->first();

            if ($operation !== null) {
                if (! hash_equals((string) $operation->server_fingerprint, $fingerprint)) {
                    throw new RuntimeException('operation_replay_mismatch');
                }

                $runId = data_get($operation->response_metadata, 'run_uuid');
                if (! is_string($runId) || $runId === '') {
                    throw new RuntimeException('research_operation_result_missing');
                }

                return GtmProfileResearchRun::query()
                    ->whereKey($runId)
                    ->where('gtm_profile_id', $lockedProfile->id)
                    ->firstOrFail();
            }

            $state = (string) $lockedProfile->getRawOriginal('research_state');
            if (! $supersedeBusy && in_array($state, self::BUSY_STATES, true)) {
                throw new RuntimeException('research_in_progress');
            }

            if ($action === 'refresh' && ! $supersedeBusy) {
                $this->assertRefreshCooldown($lockedProfile);
                $this->assertWorkspaceOverlapLimit($lockedProfile);
            }

            $currentRun = $this->currentRun($lockedProfile);
            $phaseExhausted = $currentRun !== null && $this->runHasExhaustedPhase($currentRun);
            $reuseRun = $action === 'retry'
                && $currentRun !== null
                && ! in_array((string) $currentRun->safe_failure_code, ['paid_result_lost', 'retry_exhausted'], true)
                && ! $phaseExhausted
                && $this->budgetRunAllowsRetry($currentRun)
                && in_array($state, self::RETRYABLE_STATES, true);

            if (! $reuseRun) {
                // Every replacement logical run supersedes all work that can
                // still produce output for the previous run. This applies to
                // ordinary refresh/retry as well as website changes.
                $this->supersedeActiveRuns($lockedProfile);
            }

            if ($action === 'retry' && ! $reuseRun && $state === 'ready') {
                throw new RuntimeException('research_retry_not_available');
            }
            if (! $reuseRun && $currentRun !== null
                && (string) $currentRun->getRawOriginal('state') === GtmResearchRunState::Failed->value
                && ($phaseExhausted || (string) $currentRun->safe_failure_code === 'retry_exhausted')) {
                $this->finalizeExhaustedRunForReplacement($currentRun);
            }

            if ($reuseRun) {
                $run = $this->requeueExistingRun($currentRun, $actor, $version, $website);
            } else {
                $run = GtmProfileResearchRun::query()->create([
                    'id' => (string) Str::uuid(),
                    'gtm_profile_id' => $lockedProfile->id,
                    'state' => GtmResearchRunState::Queued,
                    'awaiting_reason' => null,
                    'budget_run_id' => null,
                    'parent_run_id' => $currentRun?->id,
                    'lineage_type' => $action === 'refresh' ? 'refresh' : 'retry',
                    'website_url_snapshot' => $website,
                    'base_profile_version_id' => $version->id,
                    'input_hash' => $this->researchInputHash($website, (string) $version->id),
                    'initiated_by_user_id' => $actor->id,
                    'safe_failure_code' => null,
                    'queued_at' => now(),
                    'started_at' => null,
                    'finished_at' => null,
                    'failed_at' => null,
                ]);
            }

            $this->queueDispatch($lockedProfile, $run);
            $lockedProfile->forceFill([
                'current_research_run_id' => $run->id,
                'research_state' => GtmProfileResearchState::Queued,
                'awaiting_reason' => null,
                'safe_error_code' => null,
            ])->save();

            $this->recordOperation(
                $lockedProfile,
                $clientOperationId,
                $fingerprint,
                $version,
                $run,
                $action,
            );
            $this->registerAfterCommitDispatch($lockedProfile, $run);

            return $run;
        }, 3);
    }

    public function refresh(
        GtmProfile $profile,
        User $actor,
        ?string $clientOperationId = null,
    ): GtmProfileResearchRun {
        return $this->handle($profile, $actor, 'refresh', $clientOperationId);
    }

    /**
     * Start a fresh run for a newly changed website, superseding any queued
     * or running run that still points at the previous website.
     */
    public function refreshForWebsiteChange(
        GtmProfile $profile,
        User $actor,
        ?string $clientOperationId = null,
    ): GtmProfileResearchRun {
        return $this->handle($profile, $actor, 'refresh', $clientOperationId, true);
    }

    /**
     * Clear all durable research work when the canonical website is removed.
     *
     * This public entry point is used by the website mutation service while it
     * is already inside its profile/version transaction. The nested Laravel
     * transaction is therefore part of the same database commit (or savepoint)
     * and cannot leave a live dispatch behind a website removal.
     */
    public function cancelForWebsiteRemoval(GtmProfile $profile, User $actor): void
    {
        DB::transaction(function () use ($profile, $actor): void {
            $lockedProfile = GtmProfile::query()
                ->whereKey($profile->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertOwner($lockedProfile, $actor);
            $this->supersedeActiveRuns($lockedProfile);
        }, 3);
    }

    public function retry(
        GtmProfile $profile,
        User $actor,
        ?string $clientOperationId = null,
    ): GtmProfileResearchRun {
        return $this->handle($profile, $actor, 'retry', $clientOperationId);
    }

    private function operationId(?string $operationId): string
    {
        if ($operationId === null) {
            return (string) Str::uuid();
        }

        if (trim($operationId) === '' || strlen($operationId) > 128) {
            throw ValidationException::withMessages([
                'client_operation_id' => 'The client operation ID is invalid.',
            ]);
        }

        return $operationId;
    }

    private function fingerprint(
        GtmProfile $profile,
        string $action,
        GtmProfileVersion $version,
        string $website,
    ): string {
        return CanonicalGtmProfilePayload::hash([
            'aggregate' => 'gtm_profile',
            'aggregate_id' => (string) $profile->id,
            'action' => 'research',
            'research_action' => $action,
            'website_url' => $website,
            'base_profile_version_id' => (string) $version->id,
            'base_profile_version' => (int) $version->version,
        ]);
    }

    private function researchInputHash(string $website, string $versionId): string
    {
        return hash('sha256', json_encode([
            'website_url' => $website,
            'base_profile_version_id' => $versionId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function assertRefreshCooldown(GtmProfile $profile): void
    {
        $cooldown = max(0, (int) config('gtm.research.refresh_cooldown_seconds', 3600));
        if ($cooldown === 0) {
            return;
        }

        $latest = GtmProfileResearchRun::query()
            ->where('gtm_profile_id', $profile->id)
            ->whereIn('lineage_type', ['refresh', 'retry'])
            ->where('created_at', '>', now()->subSeconds($cooldown))
            ->latest('created_at')
            ->lockForUpdate()
            ->first();
        if ($latest !== null) {
            throw new RuntimeException('research_refresh_cooldown');
        }
    }

    private function assertWorkspaceOverlapLimit(GtmProfile $profile): void
    {
        $maximum = max(1, (int) config('gtm.research.max_concurrent_per_workspace', 1));
        $active = GtmProfileResearchRun::query()
            ->whereHas('profile', fn ($query) => $query->where('workspace_id', $profile->workspace_id))
            ->whereIn('state', [GtmResearchRunState::Queued->value, GtmResearchRunState::Running->value])
            ->count();
        if ($active >= $maximum) {
            throw new RuntimeException('research_workspace_busy');
        }
    }

    private function assertOwner(GtmProfile $profile, User $actor): void
    {
        $workspace = $profile->workspace()->first();
        if ($workspace === null || ! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can refresh profile research.');
        }
    }

    private function currentVersion(GtmProfile $profile): GtmProfileVersion
    {
        $version = GtmProfileVersion::query()
            ->where('gtm_profile_id', $profile->id)
            ->whereKey($profile->current_version_id)
            ->lockForUpdate()
            ->first();

        if ($version === null) {
            throw new RuntimeException('profile_version_missing');
        }

        return $version;
    }

    private function currentRun(GtmProfile $profile): ?GtmProfileResearchRun
    {
        $runId = $profile->getRawOriginal('current_research_run_id');
        if (! is_string($runId) || $runId === '') {
            return null;
        }

        return GtmProfileResearchRun::query()
            ->whereKey($runId)
            ->where('gtm_profile_id', $profile->id)
            ->lockForUpdate()
            ->first();
    }

    private function budgetRunAllowsRetry(GtmProfileResearchRun $run): bool
    {
        $budgetRunId = $run->getRawOriginal('budget_run_id');
        if (! is_string($budgetRunId) || $budgetRunId === '') {
            return true;
        }

        $budgetRun = AiBudgetRun::query()->whereKey($budgetRunId)->first();

        return $budgetRun !== null
            && (string) $budgetRun->status === 'open'
            && $budgetRun->closed_at === null;
    }

    private function runHasExhaustedPhase(GtmProfileResearchRun $run): bool
    {
        $maximumAttempts = max(1, (int) config('gtm.research.max_attempts', 3));

        return $run->phases()
            ->whereNotIn('status', ['succeeded', 'skipped'])
            ->whereHas('attempts', fn ($query) => $query->where('attempt_number', '>=', $maximumAttempts))
            ->exists();
    }

    private function finalizeExhaustedRunForReplacement(GtmProfileResearchRun $run): void
    {
        $run->forceFill([
            'state' => GtmResearchRunState::Failed,
            'awaiting_reason' => null,
            'safe_failure_code' => 'retry_exhausted',
            'failed_at' => $run->failed_at ?? now(),
            'finished_at' => $run->finished_at ?? now(),
        ])->save();

        $budgetRunId = $run->getRawOriginal('budget_run_id');
        if (! is_string($budgetRunId) || $budgetRunId === '') {
            return;
        }
        $budgetRun = AiBudgetRun::query()->whereKey($budgetRunId)->first();
        if ($budgetRun === null || $budgetRun->closed_at !== null) {
            return;
        }

        try {
            $this->budget->closeRun($budgetRun);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('research_retry_finalizing', 0, $exception);
        }
    }

    /**
     * Atomically terminalize all runs that could still produce work for the
     * previous website and retire their durable dispatch rows.
     *
     * Runs with an operation that may have reached a provider are deliberately
     * left for AF-01 reconciliation. cancelRun() refuses to release those
     * reservations, which is the safety boundary that prevents an uncertain
     * provider charge from being silently discarded. Definitely-unsent
     * reservations are cancelled immediately and their held budget is released.
     */
    private function supersedeActiveRuns(GtmProfile $profile): void
    {
        $runs = GtmProfileResearchRun::query()
            ->where('gtm_profile_id', $profile->id)
            ->whereIn('state', self::SUPERSEDE_STATES)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($runs as $run) {
            $run->forceFill([
                'state' => GtmResearchRunState::Cancelled,
                'awaiting_reason' => null,
                'safe_failure_code' => 'research_superseded',
                'finished_at' => now(),
                'failed_at' => null,
            ])->save();

            $dispatch = GtmProfileResearchDispatch::query()
                ->where('gtm_profile_id', $profile->id)
                ->where('research_run_id', $run->id)
                ->lockForUpdate()
                ->first();
            if ($dispatch !== null) {
                $dispatch->forceFill([
                    'status' => 'retired',
                    'published_at' => $dispatch->published_at ?? now(),
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => 'research_superseded',
                ])->save();
            }

            $this->cancelBudgetRun($profile, $run);
        }
    }

    private function cancelBudgetRun(GtmProfile $profile, GtmProfileResearchRun $run): void
    {
        $budgetRunId = $run->getRawOriginal('budget_run_id');
        if (! is_string($budgetRunId) || $budgetRunId === '') {
            return;
        }

        $budgetRun = AiBudgetRun::query()
            ->whereKey($budgetRunId)
            ->where('workspace_id', $profile->workspace_id)
            ->first();
        if ($budgetRun === null || ! in_array((string) $budgetRun->status, ['open', 'breached'], true)) {
            return;
        }

        try {
            $this->budget->cancelRun(
                $budgetRun,
                'research_superseded',
                'The research target changed before this budget run completed.',
            );
        } catch (RuntimeException) {
            // An executing/uncertain/reconciling operation may have reached a
            // provider. Keep that run open for AF-01 reconciliation rather
            // than claiming the reservation was definitely not sent.
        }
    }

    private function website(GtmProfileVersion $version): ?string
    {
        $website = data_get($version->payload, 'company.website_url.value');

        return is_string($website) && $website !== '' ? $website : null;
    }

    private function requeueExistingRun(
        GtmProfileResearchRun $run,
        User $actor,
        GtmProfileVersion $version,
        string $website,
    ): GtmProfileResearchRun {
        $run->forceFill([
            'state' => GtmResearchRunState::Queued,
            'awaiting_reason' => null,
            'website_url_snapshot' => $website,
            'base_profile_version_id' => $version->id,
            'input_hash' => $this->researchInputHash($website, (string) $version->id),
            'initiated_by_user_id' => $actor->id,
            'safe_failure_code' => null,
            'queued_at' => now(),
            'started_at' => null,
            'finished_at' => null,
            'failed_at' => null,
        ])->save();

        return $run->fresh();
    }

    private function queueDispatch(GtmProfile $profile, GtmProfileResearchRun $run): void
    {
        $dispatch = GtmProfileResearchDispatch::query()
            ->where('gtm_profile_id', $profile->id)
            ->where('research_run_id', $run->id)
            ->lockForUpdate()
            ->first();

        if ($dispatch === null) {
            GtmProfileResearchDispatch::query()->create([
                'gtm_profile_id' => $profile->id,
                'research_run_id' => $run->id,
                'status' => 'pending',
                'available_at' => now(),
                'published_at' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'publish_attempts' => 0,
                'last_error_code' => null,
            ]);

            return;
        }

        $dispatch->forceFill([
            'status' => 'pending',
            'available_at' => now(),
            'published_at' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'last_error_code' => null,
        ])->save();
    }

    private function recordOperation(
        GtmProfile $profile,
        string $operationId,
        string $fingerprint,
        GtmProfileVersion $version,
        GtmProfileResearchRun $run,
        string $action,
    ): void {
        GtmProfileClientOperation::query()->create([
            'gtm_profile_id' => $profile->id,
            'client_operation_id' => $operationId,
            'server_fingerprint' => $fingerprint,
            'expected_lock_version' => (int) $profile->lock_version,
            'resulting_lock_version' => (int) $profile->lock_version,
            'expected_version_id' => $version->id,
            'resulting_version_id' => $version->id,
            'response_metadata' => [
                'profile_id' => $profile->id,
                'run_uuid' => $run->id,
                'action' => $action,
                'lock_version' => (int) $profile->lock_version,
            ],
        ]);
    }

    private function registerAfterCommitDispatch(
        GtmProfile $profile,
        GtmProfileResearchRun $run,
    ): void {
        $jobClass = 'App\\Jobs\\Gtm\\ResearchWorkspaceGtmProfile';
        $profileId = (string) $profile->id;
        $runId = (string) $run->id;
        $initiatingUserId = $run->initiated_by_user_id !== null
            ? (int) $run->initiated_by_user_id
            : null;

        DB::afterCommit(function () use ($jobClass, $profileId, $runId, $initiatingUserId): void {
            if (! class_exists($jobClass)) {
                return;
            }

            $job = new $jobClass($profileId, $runId, $initiatingUserId);
            dispatch($job);
        });
    }
}
