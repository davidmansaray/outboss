<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Ai\DTOs\ResearchExtractRequest;
use App\Domain\Ai\DTOs\ResearchResult;
use App\Domain\Ai\DTOs\ResearchSearchRequest;
use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Exceptions\AiBudgetExceeded;
use App\Domain\Ai\Exceptions\SafeProviderException;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Ai\Services\AiCostEstimator;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Ai\Services\ExecuteProviderOperation;
use App\Domain\Gtm\Enums\GtmProfileAwaitingReason;
use App\Domain\Gtm\Enums\GtmProfileResearchState;
use App\Domain\Gtm\Enums\GtmResearchPhaseStatus;
use App\Domain\Gtm\Enums\GtmResearchRunState;
use App\Domain\Gtm\Exceptions\GtmPayloadValidationException;
use App\Domain\Gtm\Exceptions\GtmResearchPaidResultLost;
use App\Domain\Gtm\Exceptions\GtmResearchPhaseBusy;
use App\Domain\Gtm\Exceptions\GtmResearchReconciliationPending;
use App\Domain\Gtm\Exceptions\GtmResearchRetryExhausted;
use App\Domain\Gtm\Support\GtmResearchOutputValidator;
use App\Domain\Gtm\Support\PublicWebsiteUrl;
use App\Models\AiBudgetRun;
use App\Models\AiBudgetSetting;
use App\Models\AiProviderCredential;
use App\Models\AiProviderOperation;
use App\Models\AiTaskSetting;
use App\Models\GtmProfile;
use App\Models\GtmProfileResearchAttempt;
use App\Models\GtmProfileResearchDispatch;
use App\Models\GtmProfileResearchRun;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

/**
 * Execute the bounded website research pipeline for one durable run.
 *
 * The job passes only IDs into this service. Every phase reloads and checks
 * the current profile/run before provider work and before persistence, so a
 * refresh or website change can safely supersede an older worker.
 */
final class RunGtmProfileResearch
{
    public function __construct(
        private readonly ExecuteProviderOperation $executor,
        private readonly AiBudgetGateway $budget,
        private readonly AiCostEstimator $costs,
        private readonly AiWorkspaceSettings $settings,
        private readonly GtmResearchPhaseService $phases,
        private readonly GtmResearchOutputValidator $outputValidator,
        private readonly PersistGtmProfileResearchSources $sourceRecorder,
        private readonly MergeGtmProfileResearch $merge,
    ) {}

    public function handle(string $profileId, string $runUuid, ?int $initiatingUserId): void
    {
        $context = $this->context($profileId, $runUuid);
        if ($context === null) {
            return;
        }
        [$profile, $run, $workspace] = $context;
        $this->acknowledgeDispatch($profile, $run);

        $actor = $this->actor($workspace, $initiatingUserId);
        if ($actor === null || $workspace->membershipFor($actor) === null) {
            $this->cancelForMembershipLoss($profile, $run);

            return;
        }
        if ($run->website_url_snapshot === null || $run->website_url_snapshot === '') {
            $this->failRun($profile, $run, 'research_failed');

            return;
        }
        $pendingPaidResult = $this->hasTerminalProviderResultWithoutCheckpoint($run, $workspace);
        if (! $pendingPaidResult && ! $this->providersReady($workspace)) {
            $this->await($profile, $run, GtmProfileAwaitingReason::Provider);

            return;
        }
        if (! $pendingPaidResult
            && ! $this->hasActiveProviderAttempt($run, $workspace)
            && ! $this->budgetMayAdmit($workspace, $run)) {
            $this->await($profile, $run, GtmProfileAwaitingReason::Budget);

            return;
        }

        if (! $this->markRunning($profile, $run)) {
            return;
        }

        try {
            $budgetRun = $this->budgetRun($workspace, $run, $actor);
            $extract = $this->extract($profile, $run, $workspace, $actor, $budgetRun);
            $this->sourceRecorder->handle(
                $profile,
                $run,
                $this->sourceSpecs($extract, []),
                $this->phaseOperationId($run, 'extract'),
            );
            $search = $this->search($profile, $run, $workspace, $actor, $budgetRun, $extract);
            $sources = $this->sourceSpecs($extract, $search);
            $this->sourceRecorder->handle(
                $profile,
                $run,
                $sources,
                $this->phaseOperationId($run, 'extract'),
                $this->phaseOperationId($run, 'search'),
            );
            $generated = $this->generate($profile, $run, $workspace, $actor, $budgetRun, $sources);

            if (! $this->isCurrent($profile->id, $run->id)) {
                return;
            }

            $this->merge->handle(
                $profile,
                $run,
                $generated['output'],
                $sources,
                $this->phaseOperationId($run, 'extract'),
                $this->phaseOperationId($run, 'search'),
                $generated['citations'],
            );
            $this->closeBudgetRun($budgetRun);
        } catch (GtmResearchPhaseBusy) {
            // A second worker may die after begin() but before creating its
            // AF-01 operation. Keep a durable retry scheduled beyond the
            // attempt lease; superseded runs are ignored by this helper.
            $this->requeueAfterBusy($profile, $run);

            return;
        } catch (GtmResearchReconciliationPending) {
            // Keep the run recoverable while AF-01 reconciles the operation.
            // The next worker will either resume the phase or resolve the
            // irreducible paid-result-loss boundary safely.
            $this->await($profile, $run, GtmProfileAwaitingReason::Provider, 'provider_uncertain');

            return;
        } catch (AiBudgetExceeded) {
            $this->await($profile, $run, GtmProfileAwaitingReason::Budget);
        } catch (GtmResearchPaidResultLost) {
            $this->failRun($profile, $run, 'paid_result_lost');
        } catch (GtmResearchRetryExhausted) {
            $this->failRun($profile, $run, 'retry_exhausted');
        } catch (SafeProviderException $exception) {
            if ($exception->ambiguous) {
                $this->await($profile, $run, GtmProfileAwaitingReason::Provider, 'provider_uncertain');
            } else {
                $this->failRun($profile, $run, $this->safeCode($exception->safeCode));
            }
        } catch (JsonException|ValidationException|GtmPayloadValidationException) {
            $this->failRun($profile, $run, 'output_invalid');
        } catch (RuntimeException $exception) {
            if ($this->providerFailureBeforeCall($exception)) {
                $this->await($profile, $run, GtmProfileAwaitingReason::Provider);
            } else {
                $this->failRun($profile, $run, 'research_failed');
            }
        }
    }

    /**
     * Return whether an awaiting run can be put back on the durable dispatch
     * queue. This deliberately performs only local, read-only checks: no
     * provider call or budget reservation is made by the sweeper.
     */
    public function canResume(GtmProfile $profile, GtmProfileResearchRun $run): bool
    {
        $workspace = $profile->relationLoaded('workspace')
            ? $profile->workspace
            : $profile->workspace()->first();
        if ($workspace === null
            || (string) $profile->getRawOriginal('current_research_run_id') !== (string) $run->id
            || in_array((string) $run->getRawOriginal('state'), [
                GtmResearchRunState::Ready->value,
                GtmResearchRunState::Cancelled->value,
            ], true)) {
            return false;
        }

        $providerRecovery = $this->providerRecoveryState($run, $workspace);
        if ($providerRecovery === 'paid_result_lost') {
            return true;
        }
        if ($providerRecovery === 'pending') {
            return false;
        }

        if (! $this->providersReady($workspace)) {
            return false;
        }

        return $this->budgetMayAdmit($workspace, $run);
    }

    /**
     * Provider readiness is exposed separately so recovery can distinguish a
     * provider wait from a budget wait without reserving anything.
     */
    public function providersAvailable(Workspace $workspace): bool
    {
        return $this->providersReady($workspace);
    }

    /**
     * Requeue an awaiting run after its local prerequisites recover. The
     * state transition and durable dispatch reset are one transaction; the
     * publisher may safely claim the row after commit.
     */
    public function recoverAwaitingRun(
        GtmProfile $profile,
        GtmProfileResearchRun $run,
        bool $budgetCheck = true,
    ): bool {
        $workspace = $profile->relationLoaded('workspace')
            ? $profile->workspace
            : $profile->workspace()->first();
        if ($workspace === null) {
            return false;
        }

        if ($run->initiated_by_user_id !== null) {
            $initiator = User::query()->find($run->initiated_by_user_id);
            if ($initiator === null || $workspace->membershipFor($initiator) === null) {
                $this->cancelForMembershipLoss($profile, $run);

                return false;
            }
        }

        $providerRecovery = $this->providerRecoveryState($run, $workspace);
        if ($providerRecovery === 'pending') {
            return false;
        }
        if ($providerRecovery !== 'paid_result_lost'
            && (! $this->providersReady($workspace)
                || ($budgetCheck && ! $this->budgetMayAdmit($workspace, $run)))) {
            return false;
        }

        return DB::transaction(function () use ($profile, $run): bool {
            $lockedProfile = GtmProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
            $lockedRun = GtmProfileResearchRun::query()
                ->whereKey($run->id)
                ->where('gtm_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();
            if ($lockedProfile === null || $lockedRun === null
                || (string) $lockedProfile->current_research_run_id !== (string) $lockedRun->id
                || ! in_array((string) $lockedRun->getRawOriginal('state'), [
                    GtmResearchRunState::AwaitingProvider->value,
                    GtmResearchRunState::AwaitingBudget->value,
                ], true)) {
                return false;
            }

            $lockedProfile->forceFill([
                'research_state' => GtmProfileResearchState::Queued,
                'awaiting_reason' => null,
                'safe_error_code' => null,
            ])->save();
            $lockedRun->forceFill([
                'state' => GtmResearchRunState::Queued,
                'awaiting_reason' => null,
                'safe_failure_code' => null,
                'queued_at' => now(),
            ])->save();

            $dispatch = GtmProfileResearchDispatch::query()
                ->where('gtm_profile_id', $lockedProfile->id)
                ->where('research_run_id', $lockedRun->id)
                ->lockForUpdate()
                ->first();
            if ($dispatch === null) {
                GtmProfileResearchDispatch::query()->create([
                    'gtm_profile_id' => $lockedProfile->id,
                    'research_run_id' => $lockedRun->id,
                    'status' => 'pending',
                    'available_at' => now(),
                    'published_at' => null,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'publish_attempts' => 0,
                    'last_error_code' => null,
                ]);
            } else {
                $dispatch->forceFill([
                    'status' => 'pending',
                    'available_at' => now(),
                    'published_at' => null,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => null,
                ])->save();
            }

            return true;
        }, 3);
    }

    /** Mark a timed-out/failed queued job without exposing exception text. */
    public function failById(string $profileId, string $runUuid, string $safeCode = 'research_timed_out'): void
    {
        $context = $this->context($profileId, $runUuid);
        if ($context !== null) {
            [$profile, $run] = $context;
            $attempt = GtmProfileResearchAttempt::query()
                ->where('research_run_id', $run->id)
                ->whereIn('status', ['running', 'provider_boundary', 'planned', 'ambiguous'])
                ->orderByDesc('id')
                ->first();
            if ($attempt !== null) {
                try {
                    $this->phases->classifyFailure($attempt, $this->safeCode($safeCode));
                } catch (GtmResearchReconciliationPending) {
                    $this->await($profile, $run, GtmProfileAwaitingReason::Provider, 'provider_uncertain');

                    return;
                } catch (GtmResearchPaidResultLost) {
                    $this->failRun($profile, $run, 'paid_result_lost');

                    return;
                } catch (GtmResearchPhaseBusy) {
                    return;
                }
            }
            $this->failRun($profile, $run, $this->safeCode($safeCode));
        }
    }

    /**
     * @return array{0: GtmProfile, 1: GtmProfileResearchRun, 2: Workspace}|null
     */
    private function context(string $profileId, string $runUuid): ?array
    {
        $profile = GtmProfile::query()->with('workspace')->find($profileId);
        if ($profile === null) {
            return null;
        }
        $run = GtmProfileResearchRun::query()
            ->whereKey($runUuid)
            ->where('gtm_profile_id', $profile->id)
            ->first();
        $workspace = $profile->workspace;
        if ($run === null || $workspace === null) {
            return null;
        }
        if ((string) $profile->current_research_run_id !== (string) $run->id) {
            return null;
        }

        return [$profile, $run, $workspace];
    }

    private function actor(Workspace $workspace, ?int $initiatingUserId): ?User
    {
        if ($initiatingUserId !== null) {
            // A queued run is attributed to the initiating actor. Falling
            // back to a different owner after that actor disappears would
            // silently change authority and spend on their behalf.
            return User::query()->find($initiatingUserId);
        }

        return $workspace->memberships()
            ->where('role', 'owner')
            ->with('user')
            ->first()?->user;
    }

    private function providersReady(Workspace $workspace): bool
    {
        foreach ([AiProviderName::Tavily, AiProviderName::OpenRouter] as $provider) {
            try {
                $ready = AiProviderCredential::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('provider', $provider)
                    ->where('capability', $provider->capability())
                    ->where('status', AiCredentialStatus::Healthy)
                    ->with('activeVersion')
                    ->get()
                    ->contains(function (AiProviderCredential $credential): bool {
                        $version = $credential->activeVersion;

                        return $version !== null
                            && $version->wiped_at === null
                            && $version->encrypted_secret !== null;
                    });
            } catch (\Throwable) {
                $ready = false;
            }
            if (! $ready) {
                return false;
            }
        }

        return true;
    }

    private function budgetMayAdmit(Workspace $workspace, GtmProfileResearchRun $run): bool
    {
        try {
            $this->settings->ensureDefaults($workspace);
            $nextCost = $this->nextProviderCostMicros($workspace, $run);
            if ($nextCost === 0) {
                return true;
            }
            $budgetRun = $run->budget_run_id === null
                ? null
                : AiBudgetRun::query()
                    ->whereKey($run->budget_run_id)
                    ->where('workspace_id', $workspace->id)
                    ->first();

            return $this->budget->mayAdmit($workspace, AiTask::AccountResearch, $nextCost, $budgetRun);
        } catch (\Throwable) {
            return false;
        }
    }

    private function markRunning(GtmProfile $profile, GtmProfileResearchRun $run): bool
    {
        return DB::transaction(function () use ($profile, $run): bool {
            $lockedProfile = GtmProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
            $lockedRun = GtmProfileResearchRun::query()
                ->whereKey($run->id)
                ->where('gtm_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();
            if ($lockedProfile === null || $lockedRun === null
                || (string) $lockedProfile->current_research_run_id !== (string) $lockedRun->id
                || in_array($lockedRun->state->value, [
                    GtmResearchRunState::Ready->value,
                    GtmResearchRunState::Cancelled->value,
                    GtmResearchRunState::Failed->value,
                ], true)) {
                return false;
            }

            $lockedProfile->forceFill([
                'research_state' => GtmProfileResearchState::Running,
                'awaiting_reason' => null,
                'safe_error_code' => null,
            ])->save();
            $lockedRun->forceFill([
                'state' => GtmResearchRunState::Running,
                'awaiting_reason' => null,
                'started_at' => $lockedRun->started_at ?? now(),
            ])->save();

            return true;
        }, 3);
    }

    private function budgetRun(Workspace $workspace, GtmProfileResearchRun $run, User $actor): AiBudgetRun
    {
        $this->settings->ensureDefaults($workspace);

        return DB::transaction(function () use ($workspace, $run, $actor): AiBudgetRun {
            $lockedRun = GtmProfileResearchRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $profile = GtmProfile::query()->whereKey($lockedRun->gtm_profile_id)->lockForUpdate()->firstOrFail();
            if ((string) $profile->current_research_run_id !== (string) $lockedRun->id) {
                throw new GtmResearchPhaseBusy('The research run has been superseded.');
            }

            if ($lockedRun->budget_run_id !== null) {
                $existing = AiBudgetRun::query()
                    ->whereKey($lockedRun->budget_run_id)
                    ->where('workspace_id', $workspace->id)
                    ->first();
                if ($existing === null) {
                    throw new RuntimeException('The research budget run is unavailable.');
                }
                $run->forceFill(['budget_run_id' => $existing->id]);

                return $existing;
            }

            $attemptKeys = GtmProfileResearchAttempt::query()
                ->where('research_run_id', $lockedRun->id)
                ->pluck('deterministic_idempotency_key');
            if ($attemptKeys->isNotEmpty()) {
                $budgetRunIds = AiProviderOperation::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('idempotency_key', $attemptKeys->all())
                    ->pluck('budget_run_id')
                    ->unique()
                    ->values();
                if ($budgetRunIds->count() > 1) {
                    throw new RuntimeException('The research operations belong to multiple budget runs.');
                }
                $recoveredBudgetRunId = $budgetRunIds->first();
                if (is_string($recoveredBudgetRunId) && $recoveredBudgetRunId !== '') {
                    $recovered = AiBudgetRun::query()
                        ->whereKey($recoveredBudgetRunId)
                        ->where('workspace_id', $workspace->id)
                        ->firstOrFail();
                    $lockedRun->forceFill(['budget_run_id' => $recovered->id])->save();
                    $run->forceFill(['budget_run_id' => $recovered->id]);

                    return $recovered;
                }
            }

            $budgetRun = $this->budget->openRun($workspace, $actor, AiTask::AccountResearch);
            $lockedRun->forceFill(['budget_run_id' => $budgetRun->id])->save();
            $run->forceFill(['budget_run_id' => $budgetRun->id]);

            return $budgetRun;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function extract(
        GtmProfile $profile,
        GtmProfileResearchRun $run,
        Workspace $workspace,
        User $actor,
        AiBudgetRun $budgetRun,
    ): array {
        $inputHash = hash('sha256', 'extract|'.$run->website_url_snapshot);
        $begin = $this->phases->begin($run, 'extract', $inputHash);
        if ($begin['checkpoint'] !== null) {
            return $begin['checkpoint'];
        }
        $attempt = $begin['attempt'];
        if (! $attempt instanceof GtmProfileResearchAttempt) {
            throw new RuntimeException('The extract phase could not be started.');
        }
        $this->assertCurrent($profile, $run);
        $this->revalidateWebsite($run);

        try {
            $this->assertBudgetCanAdmit(
                $workspace,
                $budgetRun,
                $this->tavilyExtractCostMicros($workspace),
            );
            $request = new ResearchExtractRequest([$run->website_url_snapshot], 'basic');
            $attempt = $this->phases->claimProviderBoundary($attempt);
            $result = $this->executor->extract(
                $workspace,
                $actor,
                $request,
                $attempt->deterministic_idempotency_key,
                $budgetRun->id,
            );
            $operationId = $this->operationId($workspace, $attempt->deterministic_idempotency_key);
            if ($operationId === null) {
                $this->phases->markPaidResultLost($attempt);
            }
            $checkpoint = [
                'sources' => $this->providerSources($result, 'extract', $run->website_url_snapshot),
                'provider_request_id' => $result->providerRequestId,
            ];
            try {
                $this->phases->attachProviderOperation($attempt, $operationId);
                $this->phases->checkpoint($attempt, $checkpoint);
            } catch (GtmResearchPhaseBusy $exception) {
                throw $exception;
            } catch (GtmResearchPaidResultLost $exception) {
                throw $exception;
            } catch (\Throwable) {
                $this->phases->markPaidResultLost($attempt);
            }

            return $checkpoint;
        } catch (AiBudgetExceeded $exception) {
            $this->phases->deferForBudget($attempt);
            throw $exception;
        } catch (GtmResearchPhaseBusy $exception) {
            throw $exception;
        } catch (SafeProviderException $exception) {
            $this->phases->classifyFailure($attempt, $this->safeCode($exception->safeCode));
            throw $exception;
        } catch (JsonException|ValidationException|GtmPayloadValidationException $exception) {
            $this->phases->recordInspectedFailure($attempt);
            throw $exception;
        } catch (GtmResearchPaidResultLost $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->phases->classifyFailure($attempt);
            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $extract
     * @return array<string, mixed>
     */
    private function search(
        GtmProfile $profile,
        GtmProfileResearchRun $run,
        Workspace $workspace,
        User $actor,
        AiBudgetRun $budgetRun,
        array $extract,
    ): array {
        if ($this->extractCoverageIsSufficient($extract)) {
            $phase = $this->phases->skip($run, 'search', ['reason' => 'extract_sufficient']);

            return is_array($phase->successful_checkpoint) ? $phase->successful_checkpoint : [];
        }

        $query = trim($workspace->name.' '.$this->host($run->website_url_snapshot).' company product customers');
        $inputHash = hash('sha256', 'search|'.$query);
        $begin = $this->phases->begin($run, 'search', $inputHash);
        if ($begin['checkpoint'] !== null) {
            return $begin['checkpoint'];
        }
        $attempt = $begin['attempt'];
        if (! $attempt instanceof GtmProfileResearchAttempt) {
            throw new RuntimeException('The search phase could not be started.');
        }
        $this->assertCurrent($profile, $run);

        try {
            $this->assertBudgetCanAdmit(
                $workspace,
                $budgetRun,
                $this->tavilySearchCostMicros($workspace),
            );
            $request = new ResearchSearchRequest(
                $query,
                'basic',
                min(5, (int) config('ai.research.maximum_results', 10)),
            );
            $attempt = $this->phases->claimProviderBoundary($attempt);
            $result = $this->executor->search(
                $workspace,
                $actor,
                $request,
                $attempt->deterministic_idempotency_key,
                $budgetRun->id,
            );
            $operationId = $this->operationId($workspace, $attempt->deterministic_idempotency_key);
            if ($operationId === null) {
                $this->phases->markPaidResultLost($attempt);
            }
            $checkpoint = [
                'sources' => $this->providerSources($result, 'search', $run->website_url_snapshot),
                'provider_request_id' => $result->providerRequestId,
            ];
            try {
                $this->phases->attachProviderOperation($attempt, $operationId);
                $this->phases->checkpoint($attempt, $checkpoint);
            } catch (GtmResearchPhaseBusy $exception) {
                throw $exception;
            } catch (GtmResearchPaidResultLost $exception) {
                throw $exception;
            } catch (\Throwable) {
                $this->phases->markPaidResultLost($attempt);
            }

            return $checkpoint;
        } catch (AiBudgetExceeded $exception) {
            $this->phases->deferForBudget($attempt);
            throw $exception;
        } catch (GtmResearchPhaseBusy $exception) {
            throw $exception;
        } catch (SafeProviderException $exception) {
            $this->phases->classifyFailure($attempt, $this->safeCode($exception->safeCode));
            throw $exception;
        } catch (JsonException|ValidationException|GtmPayloadValidationException $exception) {
            $this->phases->recordInspectedFailure($attempt);
            throw $exception;
        } catch (GtmResearchPaidResultLost $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->phases->classifyFailure($attempt);
            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $extract
     * @param  array<string, mixed>  $search
     * @return list<array{url: string, title: string|null, excerpt: string|null, source_type: string, published_at: string|null}>
     */
    private function sourceSpecs(array $extract, array $search): array
    {
        $sources = [];
        foreach ([data_get($extract, 'sources', []), data_get($search, 'sources', [])] as $batch) {
            if (is_array($batch)) {
                foreach ($batch as $source) {
                    if (is_array($source)) {
                        $sources[] = $source;
                    }
                }
            }
        }

        return $this->outputValidator->validateSources($sources);
    }

    /**
     * @param  list<array{url: string, title: string|null, excerpt: string|null, source_type: string, published_at: string|null}>  $sources
     * @return array{output: array<string, mixed>, citations: array<string, list<int>>}
     */
    private function generate(
        GtmProfile $profile,
        GtmProfileResearchRun $run,
        Workspace $workspace,
        User $actor,
        AiBudgetRun $budgetRun,
        array $sources,
    ): array {
        $promptPackage = $this->researchPromptPackage($run, $sources);
        $prompt = $promptPackage['prompt'];
        $inputHash = hash('sha256', 'generate|'.$prompt);
        $begin = $this->phases->begin($run, 'generate', $inputHash);
        if ($begin['checkpoint'] !== null) {
            $output = $begin['checkpoint']['output'] ?? null;
            if (! is_array($output)) {
                throw new RuntimeException('The generation checkpoint is invalid.');
            }

            $citations = $begin['checkpoint']['citations'] ?? null;
            if (! is_array($citations)) {
                throw new RuntimeException('The generation citation checkpoint is invalid.');
            }

            return ['output' => $output, 'citations' => $citations];
        }
        $attempt = $begin['attempt'];
        if (! $attempt instanceof GtmProfileResearchAttempt) {
            throw new RuntimeException('The generate phase could not be started.');
        }
        $this->assertCurrent($profile, $run);

        try {
            $this->assertBudgetCanAdmit(
                $workspace,
                $budgetRun,
                $this->generationCostMicros($workspace, $prompt),
            );
            $attempt = $this->phases->claimProviderBoundary($attempt);
            $result = $this->executor->generate(
                $workspace,
                $actor,
                AiTask::AccountResearch,
                $prompt,
                $attempt->deterministic_idempotency_key,
                $budgetRun->id,
            );
            $decoded = json_decode($result->content, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new JsonException('The research response was not a JSON object.');
            }
            $rawCitations = $decoded['citations'] ?? null;
            unset($decoded['citations']);
            $normalized = $this->outputValidator->validate($decoded);
            unset($normalized['sources']);
            $citations = $this->outputValidator->validateCitations(
                $rawCitations,
                $promptPackage['handles'],
                $normalized,
            );
            $operationId = $this->operationId($workspace, $attempt->deterministic_idempotency_key);
            if ($operationId === null) {
                $this->phases->markPaidResultLost($attempt);
            }
            $checkpoint = [
                'output' => $normalized,
                'citations' => $citations,
                'provider_request_id' => $result->providerRequestId,
            ];
            try {
                $this->phases->attachProviderOperation($attempt, $operationId);
                $this->phases->checkpoint($attempt, $checkpoint);
            } catch (GtmResearchPhaseBusy $exception) {
                throw $exception;
            } catch (GtmResearchPaidResultLost $exception) {
                throw $exception;
            } catch (\Throwable) {
                $this->phases->markPaidResultLost($attempt);
            }

            return ['output' => $normalized, 'citations' => $citations];
        } catch (AiBudgetExceeded $exception) {
            $this->phases->deferForBudget($attempt);
            throw $exception;
        } catch (GtmResearchPhaseBusy $exception) {
            throw $exception;
        } catch (SafeProviderException $exception) {
            $this->phases->classifyFailure($attempt, $this->safeCode($exception->safeCode));
            throw $exception;
        } catch (JsonException|ValidationException|GtmPayloadValidationException $exception) {
            $this->phases->recordInspectedFailure($attempt);
            throw $exception;
        } catch (GtmResearchPaidResultLost $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->phases->classifyFailure($attempt);
            throw $exception;
        }
    }

    private function nextProviderCostMicros(Workspace $workspace, GtmProfileResearchRun $run): int
    {
        $phases = $run->phases()
            ->get()
            ->keyBy('phase');
        $extractPhase = $phases->get('extract');
        if ($extractPhase === null || $extractPhase->status->value !== 'succeeded') {
            return $this->tavilyExtractCostMicros($workspace);
        }

        $extract = is_array($extractPhase->successful_checkpoint)
            ? $extractPhase->successful_checkpoint
            : [];
        $searchPhase = $phases->get('search');
        if ($searchPhase === null || ! in_array($searchPhase->status->value, ['succeeded', 'skipped'], true)) {
            if (! $this->extractCoverageIsSufficient($extract)) {
                return $this->tavilySearchCostMicros($workspace);
            }
            $search = [];
        } else {
            $search = is_array($searchPhase->successful_checkpoint)
                ? $searchPhase->successful_checkpoint
                : [];
        }

        $generatePhase = $phases->get('generate');
        if ($generatePhase !== null && $generatePhase->status->value === 'succeeded') {
            return 0;
        }

        $prompt = $this->researchPrompt($run, $this->sourceSpecs($extract, $search));

        return $this->generationCostMicros($workspace, $prompt);
    }

    /** @param array<string, mixed> $extract */
    private function extractCoverageIsSufficient(array $extract): bool
    {
        $sources = is_array($extract['sources'] ?? null) ? $extract['sources'] : [];
        $coverage = array_sum(array_map(
            static fn (mixed $source): int => is_array($source) && is_string($source['excerpt'] ?? null)
                ? strlen($source['excerpt'])
                : 0,
            $sources,
        ));

        return count($sources) >= 2 && $coverage >= 800;
    }

    private function tavilyExtractCostMicros(Workspace $workspace): int
    {
        return $this->costs->tavilyExtract(1, 'basic', $this->tavilyCreditPriceMicros($workspace))->costMicros;
    }

    private function tavilySearchCostMicros(Workspace $workspace): int
    {
        return $this->costs->tavilySearch('basic', $this->tavilyCreditPriceMicros($workspace))->costMicros;
    }

    private function tavilyCreditPriceMicros(Workspace $workspace): int
    {
        $price = AiBudgetSetting::query()->whereKey($workspace->id)->value('tavily_credit_price_micros');
        if (! is_numeric($price) || (int) $price < 0) {
            throw new RuntimeException('The research provider pricing policy is unavailable.');
        }

        return (int) $price;
    }

    private function generationCostMicros(Workspace $workspace, string $prompt): int
    {
        $policy = AiTaskSetting::query()
            ->where('workspace_id', $workspace->id)
            ->where('task', AiTask::AccountResearch->value)
            ->firstOrFail();
        $definition = config('ai.tasks.'.AiTask::AccountResearch->value);
        if (! is_array($definition)) {
            throw new RuntimeException('The account research policy is unavailable.');
        }

        return $this->costs->openRouter(
            $policy->model_id,
            strlen($prompt),
            (int) $definition['maximum_output_tokens'],
        )->costMicros;
    }

    private function assertBudgetCanAdmit(Workspace $workspace, AiBudgetRun $budgetRun, int $costMicros): void
    {
        if (! $this->budget->mayAdmit($workspace, AiTask::AccountResearch, $costMicros, $budgetRun)) {
            throw new AiBudgetExceeded('The research budget cannot admit the next provider operation.');
        }
    }

    /**
     * @param  list<array{url: string, title: string|null, excerpt: string|null, source_type: string, published_at: string|null}>  $sources
     */
    private function researchPrompt(GtmProfileResearchRun $run, array $sources): string
    {
        return $this->researchPromptPackage($run, $sources)['prompt'];
    }

    /**
     * @param  list<array{url: string, title: string|null, excerpt: string|null, source_type: string, published_at: string|null}>  $sources
     * @return array{prompt: string, handles: array<string, int>}
     */
    private function researchPromptPackage(GtmProfileResearchRun $run, array $sources): array
    {
        $context = $this->boundedResearchContext((string) $run->website_url_snapshot, $sources);
        $decodedContext = json_decode($context, true, 32, JSON_THROW_ON_ERROR);
        $visibleSources = is_array($decodedContext) ? ($decodedContext['sources'] ?? null) : null;
        if (! is_array($visibleSources)) {
            throw new RuntimeException('The bounded research source context is invalid.');
        }
        $handles = [];
        foreach ($visibleSources as $source) {
            $handle = is_array($source) ? ($source['handle'] ?? null) : null;
            if (! is_string($handle)
                || preg_match('/^SRC_([1-9][0-9]*)$/', $handle, $matches) !== 1) {
                throw new RuntimeException('The bounded research source handle is invalid.');
            }
            $sourceIndex = ((int) $matches[1]) - 1;
            if (! array_key_exists($sourceIndex, $sources)) {
                throw new RuntimeException('The bounded research source handle is out of range.');
            }
            $handles[$handle] = $sourceIndex;
        }

        $prompt = <<<PROMPT
Return only one valid JSON object. Do not use markdown fences, database IDs,
provenance, timestamps, or claims unsupported by the supplied public excerpts.
Keep every string concise. Omit unknown values rather than guessing. Each source
has an opaque `handle`. Every non-empty factual field must have a `citations`
entry using one or more handles supplied below. Never invent a source handle.

Allowed object shape:
{"synthesis":string|null,"company":{"name":string|null,"category":string|null,"product_description":string|null,"target_problem":string|null,"primary_outcome":string|null},"market":{"target_segment":string|null,"geographies":string[],"company_traits":string[],"excluded_segments":string[],"timing_thesis":string|null},"buyers":{"target_roles":string[],"buying_triggers":string[],"blocking_roles":string[]},"messaging":{"proof_points":string[],"prohibited_claims":string[],"brand_voice":string|null,"message_constraints":string[]},"qualification":{"evidence_requirements":string[],"business_disqualifiers":string[]},"citations":{"field.path":["SRC_1"]}}

Public research context:
$context
PROMPT;

        return ['prompt' => $prompt, 'handles' => $handles];
    }

    /**
     * Bound source values before encoding. The resulting context is always a
     * complete UTF-8 JSON document; encoded bytes are never sliced.
     *
     * @param  list<array{url: string, title: string|null, excerpt: string|null, source_type: string, published_at: string|null}>  $sources
     */
    private function boundedResearchContext(string $website, array $sources): string
    {
        $maximum = max(64, (int) config('gtm.research.max_context_bytes', 16 * 1024));
        $context = ['website' => $website, 'sources' => []];
        $encoded = $this->encodeResearchContext($context);
        if (strlen($encoded) > $maximum) {
            throw new RuntimeException('The research website exceeds the context byte limit.');
        }

        foreach ($sources as $sourceIndex => $source) {
            $bounded = [
                'handle' => 'SRC_'.($sourceIndex + 1),
                'url' => mb_strcut($source['url'], 0, (int) config('gtm.limits.url_max_length', 2048), 'UTF-8'),
                'title' => is_string($source['title'])
                    ? mb_strcut($source['title'], 0, (int) config('gtm.research.max_context_title_bytes', 512), 'UTF-8')
                    : null,
                'excerpt' => is_string($source['excerpt'])
                    ? mb_strcut($source['excerpt'], 0, (int) config('gtm.research.max_context_excerpt_bytes', 4096), 'UTF-8')
                    : null,
            ];
            $fitted = $this->fitSourceIntoContext($context, $bounded, $maximum);
            if ($fitted === null) {
                continue;
            }
            $context['sources'][] = $fitted;
        }

        $encoded = $this->encodeResearchContext($context);
        if (strlen($encoded) > $maximum) {
            throw new RuntimeException('The bounded research context exceeds its byte limit.');
        }

        return $encoded;
    }

    /**
     * @param  array{website: string, sources: list<array{handle: string, url: string, title: string|null, excerpt: string|null}>}  $context
     * @param  array{handle: string, url: string, title: string|null, excerpt: string|null}  $source
     * @return array{handle: string, url: string, title: string|null, excerpt: string|null}|null
     */
    private function fitSourceIntoContext(array $context, array $source, int $maximum): ?array
    {
        if ($this->contextFitsWithSource($context, $source, $maximum)) {
            return $source;
        }

        $fitted = [
            'handle' => $source['handle'],
            'url' => $source['url'],
            'title' => null,
            'excerpt' => null,
        ];
        if (! $this->contextFitsWithSource($context, $fitted, $maximum)) {
            return null;
        }

        if (is_string($source['title'])) {
            $fitted['title'] = $this->largestFittingContextString(
                $context,
                $fitted,
                'title',
                $source['title'],
                $maximum,
            );
        }
        if (is_string($source['excerpt'])) {
            $fitted['excerpt'] = $this->largestFittingContextString(
                $context,
                $fitted,
                'excerpt',
                $source['excerpt'],
                $maximum,
            );
        }

        return $fitted;
    }

    /**
     * @param  array{website: string, sources: list<array{handle: string, url: string, title: string|null, excerpt: string|null}>}  $context
     * @param  array{handle: string, url: string, title: string|null, excerpt: string|null}  $source
     */
    private function largestFittingContextString(
        array $context,
        array $source,
        string $field,
        string $value,
        int $maximum,
    ): ?string {
        $low = 0;
        $high = strlen($value);
        $best = null;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $candidate = mb_strcut($value, 0, $middle, 'UTF-8');
            if ($field === 'title') {
                $source['title'] = $candidate;
            } elseif ($field === 'excerpt') {
                $source['excerpt'] = $candidate;
            } else {
                throw new RuntimeException('The research context field is invalid.');
            }
            if ($this->contextFitsWithSource($context, $source, $maximum)) {
                $best = $candidate;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $best;
    }

    /**
     * @param  array{website: string, sources: list<array{handle: string, url: string, title: string|null, excerpt: string|null}>}  $context
     * @param  array{handle: string, url: string, title: string|null, excerpt: string|null}  $source
     */
    private function contextFitsWithSource(array $context, array $source, int $maximum): bool
    {
        $context['sources'][] = $source;

        return strlen($this->encodeResearchContext($context)) <= $maximum;
    }

    /**
     * @param  array{website: string, sources: list<array{handle: string, url: string, title: string|null, excerpt: string|null}>}  $context
     */
    private function encodeResearchContext(array $context): string
    {
        return json_encode(
            $context,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return list<array{url: string, title: string|null, excerpt: string|null, source_type: string, published_at: string|null}>
     */
    private function providerSources(ResearchResult $result, string $sourceType, string $website): array
    {
        $raw = $result->results;
        $sources = [];
        foreach (array_slice($raw, 0, (int) config('gtm.research.max_sources', 100)) as $row) {
            // Some research providers expose the post-redirect destination.
            // Prefer it when available; otherwise the abstraction only gives
            // us the returned result URL, which is still revalidated below.
            $url = $row['final_url'] ?? $row['url'] ?? $row['link'] ?? null;
            if (! is_string($url)) {
                continue;
            }
            $excerpt = $row['raw_content'] ?? $row['content'] ?? $row['snippet'] ?? null;
            $excerpt = is_string($excerpt)
                ? mb_strcut(trim(str_replace(["\r\n", "\r"], "\n", $excerpt)), 0, (int) config('gtm.limits.source_excerpt_bytes_max', 4096))
                : null;
            $title = is_string($row['title'] ?? null) ? mb_substr(trim($row['title']), 0, (int) config('gtm.limits.identity_max_length', 160)) : null;
            $sources[] = [
                'url' => $url,
                'title' => $title,
                'excerpt' => $excerpt,
                'source_type' => $sourceType,
                'published_at' => is_string($row['published_date'] ?? null) ? $row['published_date'] : null,
            ];
        }
        if ($sources === []) {
            $sources[] = [
                'url' => $website,
                'title' => null,
                'excerpt' => null,
                'source_type' => $sourceType,
                'published_at' => null,
            ];
        }

        return $this->outputValidator->validateSources($sources);
    }

    private function operationId(Workspace $workspace, string $idempotencyKey): ?string
    {
        return AiProviderOperation::query()
            ->where('workspace_id', $workspace->id)
            ->where('idempotency_key', $idempotencyKey)
            ->value('id');
    }

    private function phaseOperationId(GtmProfileResearchRun $run, string $phase): ?string
    {
        $attempt = GtmProfileResearchAttempt::query()
            ->where('research_run_id', $run->id)
            ->whereHas('phase', fn ($query) => $query->where('phase', $phase))
            ->whereNotNull('provider_operation_id')
            ->orderByDesc('attempt_number')
            ->first();

        return $attempt?->provider_operation_id;
    }

    private function hasTerminalProviderResultWithoutCheckpoint(
        GtmProfileResearchRun $run,
        Workspace $workspace,
    ): bool {
        return $this->providerRecoveryState($run, $workspace) === 'paid_result_lost';
    }

    private function hasActiveProviderAttempt(GtmProfileResearchRun $run, Workspace $workspace): bool
    {
        return $this->providerRecoveryState($run, $workspace) === 'pending';
    }

    /** @return 'none'|'pending'|'paid_result_lost' */
    private function providerRecoveryState(GtmProfileResearchRun $run, Workspace $workspace): string
    {
        $attempts = GtmProfileResearchAttempt::query()
            ->where('research_run_id', $run->id)
            ->whereHas('phase', fn ($query) => $query->whereNotIn('status', [
                GtmResearchPhaseStatus::Succeeded->value,
                GtmResearchPhaseStatus::Skipped->value,
            ]))
            ->orderByDesc('attempt_number')
            ->get();
        $pending = false;
        foreach ($attempts as $attempt) {
            if ((string) $attempt->safe_error_code === 'paid_result_lost') {
                return 'paid_result_lost';
            }
            $outcome = $attempt->getAttribute('outcome');
            if (is_array($outcome)
                && ($outcome['type'] ?? null) === 'inspected_failure'
                && ($outcome['response_inspected'] ?? null) === true) {
                continue;
            }

            $operationQuery = AiProviderOperation::query()->where('workspace_id', $workspace->id);
            if ($attempt->provider_operation_id !== null) {
                $operationQuery->whereKey($attempt->provider_operation_id);
            } else {
                $operationQuery->where('idempotency_key', $attempt->deterministic_idempotency_key);
            }
            $operation = $operationQuery->first();
            if ($operation === null) {
                if (in_array((string) $attempt->status, ['running', 'provider_boundary', 'planned', 'ambiguous'], true)
                    && $this->researchAttemptIsFresh($attempt)) {
                    $pending = true;
                }

                continue;
            }
            if (in_array($operation->status, [
                AiOperationStatus::Succeeded,
                AiOperationStatus::Reconciled,
                AiOperationStatus::ManualReview,
            ], true)) {
                return 'paid_result_lost';
            }
            if (! $operation->status->terminal()) {
                $pending = true;
            }
        }

        return $pending ? 'pending' : 'none';
    }

    private function researchAttemptIsFresh(GtmProfileResearchAttempt $attempt): bool
    {
        $leaseExpiresAt = $attempt->getAttribute('lease_expires_at');
        if ($leaseExpiresAt !== null) {
            try {
                return CarbonImmutable::parse($leaseExpiresAt)->isFuture();
            } catch (\Throwable) {
                return false;
            }
        }

        $value = $attempt->started_at;
        if ($value === null) {
            return false;
        }

        try {
            return CarbonImmutable::parse($value)
                ->addSeconds(max(1, (int) config('gtm.research.attempt_lease_seconds', 300)))
                ->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    private function host(?string $url): string
    {
        return is_string($url) ? (string) parse_url($url, PHP_URL_HOST) : '';
    }

    private function isCurrent(string $profileId, string $runId): bool
    {
        return (string) GtmProfile::query()->whereKey($profileId)->value('current_research_run_id') === $runId;
    }

    private function assertCurrent(GtmProfile $profile, GtmProfileResearchRun $run): void
    {
        if (! $this->isCurrent($profile->id, $run->id)) {
            throw new GtmResearchPhaseBusy('The research run has been superseded.');
        }
    }

    private function revalidateWebsite(GtmProfileResearchRun $run): void
    {
        $snapshot = $run->website_url_snapshot;
        try {
            $normalized = PublicWebsiteUrl::normalize($snapshot);
            if ($normalized !== null) {
                // DNS is deliberately fresh at the actual extraction boundary;
                // request/payload normalization is cheap and side-effect-free.
                PublicWebsiteUrl::assertPublicNetwork($normalized, fresh: true);
            }
        } catch (\InvalidArgumentException $exception) {
            // This is intentionally a pre-provider failure. A URL that was
            // public when queued may resolve to a private/reserved target by
            // the time a worker runs, so no provider request is allowed.
            throw new RuntimeException('website_target_unsafe', 0, $exception);
        }

        if ($snapshot === null || $normalized !== $snapshot) {
            throw new RuntimeException('website_target_changed');
        }
    }

    private function closeBudgetRun(AiBudgetRun $budgetRun): void
    {
        try {
            $fresh = AiBudgetRun::query()->whereKey($budgetRun->id)->first();
            if ($fresh !== null) {
                $this->budget->closeRun($fresh);
            }
        } catch (RuntimeException) {
            // A concurrent reconciler may still own an operation. The profile
            // merge is already committed; the normal budget reconciler will
            // release/settle the run before it is closed.
        }
    }

    private function requeueAfterBusy(GtmProfile $profile, GtmProfileResearchRun $run): void
    {
        DB::transaction(function () use ($profile, $run): void {
            $lockedProfile = GtmProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
            $lockedRun = GtmProfileResearchRun::query()
                ->whereKey($run->id)
                ->where('gtm_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();
            if ($lockedProfile === null || $lockedRun === null
                || (string) $lockedProfile->current_research_run_id !== (string) $lockedRun->id
                || in_array($lockedRun->state->value, [
                    GtmResearchRunState::Ready->value,
                    GtmResearchRunState::Cancelled->value,
                    GtmResearchRunState::Failed->value,
                ], true)) {
                return;
            }

            $availableAt = now()->addSeconds(max(
                5,
                (int) config('gtm.research.attempt_lease_seconds', 300),
            ));
            $dispatch = GtmProfileResearchDispatch::query()
                ->where('gtm_profile_id', $lockedProfile->id)
                ->where('research_run_id', $lockedRun->id)
                ->lockForUpdate()
                ->first();
            if ($dispatch === null) {
                GtmProfileResearchDispatch::query()->create([
                    'gtm_profile_id' => $lockedProfile->id,
                    'research_run_id' => $lockedRun->id,
                    'status' => 'pending',
                    'available_at' => $availableAt,
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
                'available_at' => $availableAt,
                'published_at' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'last_error_code' => null,
            ])->save();
        }, 3);
    }

    private function await(
        GtmProfile $profile,
        GtmProfileResearchRun $run,
        GtmProfileAwaitingReason $reason,
        ?string $safeCode = null,
    ): void {
        DB::transaction(function () use ($profile, $run, $reason, $safeCode): void {
            $lockedProfile = GtmProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
            $lockedRun = GtmProfileResearchRun::query()->whereKey($run->id)->where('gtm_profile_id', $profile->id)->lockForUpdate()->first();
            if ($lockedProfile === null || $lockedRun === null
                || (string) $lockedProfile->current_research_run_id !== (string) $lockedRun->id) {
                return;
            }
            $state = $reason === GtmProfileAwaitingReason::Provider
                ? GtmProfileResearchState::AwaitingProvider
                : GtmProfileResearchState::AwaitingBudget;
            $runState = $reason === GtmProfileAwaitingReason::Provider
                ? GtmResearchRunState::AwaitingProvider
                : GtmResearchRunState::AwaitingBudget;
            $lockedProfile->forceFill([
                'research_state' => $state,
                'awaiting_reason' => $reason,
                'safe_error_code' => $safeCode === null ? null : $this->safeCode($safeCode),
            ])->save();
            $lockedRun->forceFill([
                'state' => $runState,
                'awaiting_reason' => $reason,
                'safe_failure_code' => $safeCode === null ? null : $this->safeCode($safeCode),
            ])->save();
        }, 3);
    }

    private function failRun(GtmProfile $profile, GtmProfileResearchRun $run, string $safeCode): void
    {
        $budgetRunId = DB::transaction(function () use ($profile, $run, $safeCode): ?string {
            $lockedProfile = GtmProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
            $lockedRun = GtmProfileResearchRun::query()->whereKey($run->id)->where('gtm_profile_id', $profile->id)->lockForUpdate()->first();
            if ($lockedProfile === null || $lockedRun === null
                || (string) $lockedProfile->current_research_run_id !== (string) $lockedRun->id
                || in_array($lockedRun->state, [
                    GtmResearchRunState::Ready,
                    GtmResearchRunState::Cancelled,
                    GtmResearchRunState::Failed,
                ], true)) {
                return null;
            }
            $lockedProfile->forceFill([
                'research_state' => GtmProfileResearchState::Failed,
                'awaiting_reason' => null,
                'last_research_failed_at' => now(),
                'safe_error_code' => $this->safeCode($safeCode),
            ])->save();
            $lockedRun->forceFill([
                'state' => GtmResearchRunState::Failed,
                'awaiting_reason' => null,
                'safe_failure_code' => $this->safeCode($safeCode),
                'failed_at' => now(),
                'finished_at' => now(),
            ])->save();

            return is_string($lockedRun->budget_run_id) ? $lockedRun->budget_run_id : null;
        }, 3);

        // Ordinary safe failures remain retryable on the same logical run and
        // therefore retain its cumulative budget ledger. Paid-result loss is
        // irrecoverable on this key; a retry creates a new logical run, so the
        // old ledger is finalized only after AF-01 has settled the operation.
        if ($safeCode === 'paid_result_lost' && $budgetRunId !== null) {
            $budgetRun = AiBudgetRun::query()->whereKey($budgetRunId)->first();
            if ($budgetRun !== null) {
                $this->closeBudgetRun($budgetRun);
            }
        }
    }

    private function cancelForMembershipLoss(GtmProfile $profile, GtmProfileResearchRun $run): void
    {
        $budgetRunId = DB::transaction(function () use ($profile, $run): ?string {
            $lockedProfile = GtmProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
            $lockedRun = GtmProfileResearchRun::query()->whereKey($run->id)->where('gtm_profile_id', $profile->id)->lockForUpdate()->first();
            if ($lockedProfile === null || $lockedRun === null
                || (string) $lockedProfile->current_research_run_id !== (string) $lockedRun->id) {
                return null;
            }
            $lockedProfile->forceFill([
                'research_state' => GtmProfileResearchState::Cancelled,
                'awaiting_reason' => null,
                'safe_error_code' => 'initiator_membership_lost',
            ])->save();
            $lockedRun->forceFill([
                'state' => GtmResearchRunState::Cancelled,
                'awaiting_reason' => null,
                'safe_failure_code' => 'initiator_membership_lost',
                'failed_at' => now(),
                'finished_at' => now(),
            ])->save();

            return is_string($lockedRun->budget_run_id) ? $lockedRun->budget_run_id : null;
        }, 3);

        if ($budgetRunId === null) {
            return;
        }
        $budgetRun = AiBudgetRun::query()->whereKey($budgetRunId)->first();
        if ($budgetRun === null || ! in_array($budgetRun->status, ['open', 'breached'], true)) {
            return;
        }
        try {
            $this->budget->cancelRun($budgetRun, 'initiator_membership_lost', 'The initiating member left the workspace.');
        } catch (RuntimeException) {
            // An executing or uncertain operation remains under AF-01
            // reconciliation. The logical research run is still cancelled.
        }
    }

    private function acknowledgeDispatch(GtmProfile $profile, GtmProfileResearchRun $run): void
    {
        DB::table('gtm_profile_research_dispatches')
            ->where('gtm_profile_id', $profile->id)
            ->where('research_run_id', $run->id)
            ->whereNull('published_at')
            ->update([
                'status' => 'published',
                'published_at' => now(),
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function providerFailureBeforeCall(RuntimeException $exception): bool
    {
        return $exception instanceof ModelNotFoundException
            || str_contains($exception->getMessage(), 'credential')
            || str_contains($exception->getMessage(), 'provider')
            || str_contains($exception->getMessage(), 'AI provider');
    }

    private function safeCode(string $code): string
    {
        $allowed = config('gtm.safe_error_codes', []);

        return is_array($allowed) && in_array($code, $allowed, true) ? $code : 'research_failed';
    }
}
