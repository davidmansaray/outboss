<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Intelligence\Enums\AccountResearchAttemptState;
use App\Domain\Intelligence\Enums\AccountResearchRunState;
use App\Domain\Intelligence\Enums\AccountResearchStageState;
use App\Domain\Intelligence\Exceptions\AccountResearchBusy;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\AccountResearchAttempt;
use App\Models\AccountResearchRun;
use App\Models\AccountResearchStage;
use App\Models\AiProviderOperation;
use App\Models\ResearchClaim;
use App\Models\ResearchSourceSnapshot;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Durable stage leases, fencing and checkpoint boundaries for Account runs. */
final class AccountResearchStageService
{
    public function __construct(private readonly FinalizeAccountResearchBudgets $budgets) {}

    /**
     * @return array{attempt: AccountResearchAttempt|null, checkpoint: array<string,mixed>|null}
     */
    public function begin(
        AccountResearchRun|string $run,
        AccountResearchStage|string $stage,
        string $inputHash,
    ): array {
        $runId = $run instanceof AccountResearchRun ? (string) $run->id : $run;
        $stageId = $stage instanceof AccountResearchStage ? (string) $stage->id : $stage;
        $this->assertHash($inputHash);

        $result = DB::transaction(function () use ($runId, $stageId, $inputHash): array {
            $lockedRun = AccountResearchRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            $lockedStage = AccountResearchStage::query()
                ->whereKey($stageId)
                ->where('workspace_id', $lockedRun->workspace_id)
                ->where('research_run_id', $lockedRun->id)
                ->lockForUpdate()
                ->firstOrFail();

            $runState = AccountResearchRunState::tryFrom(ModelAttribute::enumValue($lockedRun->state) ?? '');
            if ($runState?->terminal() === true || $runState === AccountResearchRunState::Cancelling) {
                return ['attempt' => null, 'checkpoint' => null];
            }
            $stageState = ModelAttribute::enumValue($lockedStage->state);
            if (in_array($stageState, [AccountResearchStageState::Succeeded->value, AccountResearchStageState::Skipped->value], true)) {
                return [
                    'attempt' => null,
                    'checkpoint' => ModelAttribute::array($lockedStage->checkpoint_reference),
                ];
            }

            $latest = AccountResearchAttempt::query()
                ->where('workspace_id', $lockedRun->workspace_id)
                ->where('research_run_id', $lockedRun->id)
                ->where('stage_id', $lockedStage->id)
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();
            if ($latest !== null) {
                $attemptState = ModelAttribute::enumValue($latest->state);
                $active = in_array($attemptState, [
                    AccountResearchAttemptState::Planned->value,
                    AccountResearchAttemptState::Running->value,
                    AccountResearchAttemptState::ProviderBoundary->value,
                    AccountResearchAttemptState::WaitingBudget->value,
                    AccountResearchAttemptState::Ambiguous->value,
                ], true);

                // The attempt FK can be missing when a worker dies between
                // reserving provider work and attaching the operation. The
                // deterministic key is the durable join in that window.
                $operation = $this->operationForAttempt($lockedRun, $latest);
                if ($operation !== null && $latest->provider_operation_id === null) {
                    $latest->forceFill(['provider_operation_id' => $operation->id])->save();
                    $budgetColumn = $this->budgetColumn($operation);
                    if ($lockedRun->{$budgetColumn} !== null
                        && (string) $lockedRun->{$budgetColumn} !== (string) $operation->budget_run_id) {
                        throw new RuntimeException('The provider operation belongs to a different research budget run.');
                    }
                    $lockedRun->forceFill([
                        $budgetColumn => $lockedRun->{$budgetColumn} ?: $operation->budget_run_id,
                    ])->save();
                }
                if ($active && $this->leaseFresh($latest)) {
                    return ['attempt' => null, 'checkpoint' => null, 'busy' => 'The Account research stage is already leased.'];
                }

                if ($operation !== null) {
                    if ($operation->status === AiOperationStatus::Reserved && $active) {
                        return [
                            'attempt' => $this->reclaimAttempt($lockedRun, $lockedStage, $latest),
                            'checkpoint' => null,
                        ];
                    }
                    if (in_array($operation->status, [
                        AiOperationStatus::Succeeded,
                        AiOperationStatus::Reconciled,
                        AiOperationStatus::ManualReview,
                    ], true)) {
                        $latest->forceFill([
                            'state' => AccountResearchAttemptState::Failed,
                            'safe_error_code' => 'paid_result_lost',
                            'lease_expires_at' => null,
                            'finished_at' => now(),
                        ])->save();
                        $lockedStage->forceFill([
                            'state' => AccountResearchStageState::PaidResultLost,
                            'safe_error_code' => 'paid_result_lost',
                            'lease_owner' => null,
                            'lease_expires_at' => null,
                            'failed_at' => now(),
                        ])->save();
                        $this->finishRunForLostResult($lockedRun);

                        return ['attempt' => null, 'checkpoint' => null, 'busy' => 'The settled provider result has no durable checkpoint.'];
                    }
                    if (in_array($operation->status, [
                        AiOperationStatus::Executing,
                        AiOperationStatus::Uncertain,
                        AiOperationStatus::Reconciling,
                    ], true)) {
                        $this->markProviderUncertain($latest, $lockedStage);
                        $this->awaitProvider($lockedRun);

                        return ['attempt' => null, 'checkpoint' => null, 'busy' => 'The provider result requires reconciliation.'];
                    }
                    if (! $this->operationRetryable($operation)) {
                        // Planned/reserved work has not reached the provider,
                        // but its budget hold is not yet authoritatively
                        // released. Wait for the owner/reconciler instead of
                        // allocating a second operation/key.
                        $this->awaitProvider($lockedRun);

                        return ['attempt' => null, 'checkpoint' => null, 'busy' => 'The provider operation is still awaiting a safe terminal outcome.'];
                    }
                } elseif ($latest->provider_operation_id !== null) {
                    // A missing attached operation is not proof that no paid
                    // request happened. Fence the local attempt and wait for
                    // durable operation recovery rather than retrying.
                    $this->markProviderUncertain($latest, $lockedStage);
                    $this->awaitProvider($lockedRun);

                    return ['attempt' => null, 'checkpoint' => null, 'busy' => 'The attached provider operation is unavailable for reconciliation.'];
                }

                if ($operation === null && $attemptState === AccountResearchAttemptState::WaitingBudget->value) {
                    // Budget admission is not a provider attempt. Re-lease the
                    // same definitely-unsent work instead of consuming the
                    // bounded provider retry count every time the scheduler
                    // checks whether spending capacity has returned.
                    return [
                        'attempt' => $this->reclaimAttempt($lockedRun, $lockedStage, $latest),
                        'checkpoint' => null,
                    ];
                }

                // A pre-provider local attempt without a reservation cannot
                // have crossed the paid boundary. Retire its expired lease
                // before allocating a replacement so stale work remains
                // auditable without inflating the active-attempt status.
                $safeLocalRetry = $operation === null
                    && in_array($attemptState, [
                        AccountResearchAttemptState::Planned->value,
                        AccountResearchAttemptState::Running->value,
                    ], true);
                if ($active
                    // Any non-null operation reaching here is already proven
                    // Failed/Cancelled by the branches above.
                    && ($safeLocalRetry || $operation !== null)
                    && ! $this->leaseFresh($latest)) {
                    $latest->forceFill([
                        'state' => AccountResearchAttemptState::DefinitelyNotSent,
                        'safe_error_code' => 'execution_lease_expired',
                        'lease_expires_at' => null,
                        'finished_at' => now(),
                    ])->save();
                }
            }

            $attemptNumber = $latest === null ? 1 : (int) $latest->attempt_number + 1;
            $maxAttempts = max(1, (int) config('intelligence.research.max_attempts', config('gtm.research.max_attempts', 3)));
            if ($attemptNumber > $maxAttempts) {
                $lockedStage->forceFill(['state' => AccountResearchStageState::Failed, 'safe_error_code' => 'retry_exhausted', 'failed_at' => now()])->save();
                $lockedRun->forceFill([
                    'state' => AccountResearchRunState::Failed,
                    'safe_failure_code' => 'retry_exhausted',
                    'current_stage' => null,
                    'awaiting_reason' => null,
                    'finished_at' => now(),
                ])->save();

                return ['attempt' => null, 'checkpoint' => null, 'failure' => 'The Account research stage exhausted its retry limit.'];
            }
            $fence = max((int) ($latest === null ? null : $latest->fencing_token), $attemptNumber - 1) + 1;
            $attempt = AccountResearchAttempt::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $lockedRun->workspace_id,
                'research_run_id' => $lockedRun->id,
                'stage_id' => $lockedStage->id,
                'attempt_number' => $attemptNumber,
                'deterministic_idempotency_key' => $this->idempotencyKey($lockedRun, $lockedStage, $attemptNumber),
                'provider_operation_id' => null,
                'input_hash' => $inputHash,
                'state' => AccountResearchAttemptState::Running,
                'lease_owner' => (string) Str::uuid(),
                'fencing_token' => $fence,
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                'outcome' => null,
                'safe_error_code' => null,
                'started_at' => now(),
                'finished_at' => null,
            ]);
            $lockedStage->forceFill([
                'state' => AccountResearchStageState::Running,
                'lease_owner' => $attempt->lease_owner,
                'fencing_token' => $fence,
                'lease_expires_at' => $attempt->lease_expires_at,
                'started_at' => $lockedStage->started_at ?? now(),
                'safe_error_code' => null,
                'failed_at' => null,
            ])->save();
            $lockedRun->forceFill([
                // Acquiring a new stage lease is the durable proof that a
                // previously missing provider or budget condition was
                // re-admitted. Move the orchestration back to running so its
                // final stage can complete it; a failed provider reservation
                // will put it back into the appropriate waiting state.
                'state' => in_array(ModelAttribute::enumValue($lockedRun->state), [
                    AccountResearchRunState::Planned->value,
                    AccountResearchRunState::AwaitingProvider->value,
                    AccountResearchRunState::AwaitingBudget->value,
                ], true) ? AccountResearchRunState::Running : $lockedRun->state,
                'awaiting_reason' => null,
                'current_stage' => $lockedStage->stage_key,
                'started_at' => $lockedRun->started_at ?? now(),
            ])->save();

            return ['attempt' => $attempt, 'checkpoint' => null];
        }, 3);

        $currentRun = AccountResearchRun::query()->whereKey($runId)->first();
        if ($currentRun !== null
            && AccountResearchRunState::tryFrom(ModelAttribute::enumValue($currentRun->state) ?? '')?->terminal() === true) {
            $this->budgets->handle($currentRun);
        }

        if (is_string($result['busy'] ?? null)) {
            throw new AccountResearchBusy($result['busy']);
        }
        if (is_string($result['failure'] ?? null)) {
            throw new RuntimeException($result['failure']);
        }

        return ['attempt' => $result['attempt'], 'checkpoint' => $result['checkpoint']];
    }

    public function claimProviderBoundary(AccountResearchAttempt $attempt): AccountResearchAttempt
    {
        return DB::transaction(function () use ($attempt): AccountResearchAttempt {
            $run = AccountResearchRun::query()
                ->whereKey($attempt->research_run_id)
                ->where('workspace_id', $attempt->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();
            $stage = AccountResearchStage::query()
                ->whereKey($attempt->stage_id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = AccountResearchAttempt::query()
                ->whereKey($attempt->id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->where('stage_id', $stage->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertRunAdmitsProviderWork($run);
            if (! $this->ownsStageFence($attempt, $locked, $stage)
                || ModelAttribute::enumValue($stage->state) !== AccountResearchStageState::Running->value
                || ModelAttribute::enumValue($locked->state) !== AccountResearchAttemptState::Running->value
                || ! $this->leaseFresh($locked)) {
                throw new AccountResearchBusy('The Account research attempt no longer owns its provider boundary.');
            }
            $locked->forceFill([
                'state' => AccountResearchAttemptState::ProviderBoundary,
                'lease_expires_at' => now()->addSeconds($this->providerLeaseSeconds()),
            ])->save();
            $stage->forceFill(['lease_expires_at' => $locked->lease_expires_at])->save();

            return $locked->fresh();
        }, 3);
    }

    /** Fence a possibly-paid provider result and wait for AF-01 reconciliation. */
    public function awaitProviderReconciliation(AccountResearchAttempt $attempt): bool
    {
        return DB::transaction(function () use ($attempt): bool {
            $run = AccountResearchRun::query()
                ->whereKey($attempt->research_run_id)
                ->where('workspace_id', $attempt->workspace_id)
                ->lockForUpdate()
                ->first();
            if ($run === null) {
                return false;
            }
            $state = AccountResearchRunState::tryFrom(ModelAttribute::enumValue($run->state) ?? '');
            if ($state?->terminal() === true || $state === AccountResearchRunState::Cancelling) {
                return false;
            }
            $stage = AccountResearchStage::query()
                ->whereKey($attempt->stage_id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->lockForUpdate()
                ->first();
            if ($stage === null || $this->stageTerminal($stage)) {
                return false;
            }
            $locked = AccountResearchAttempt::query()
                ->whereKey($attempt->id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->where('stage_id', $stage->id)
                ->lockForUpdate()
                ->first();
            if ($locked === null || ! $this->ownsProviderFence($attempt, $locked, $stage)) {
                return false;
            }
            $operation = $this->operationForAttempt($run, $locked);
            if ($operation === null || ! in_array($operation->status, [
                AiOperationStatus::Executing,
                AiOperationStatus::Uncertain,
                AiOperationStatus::Reconciling,
            ], true)) {
                return false;
            }

            $this->markProviderUncertain($locked, $stage);
            $this->awaitProvider($run);

            return true;
        }, 3);
    }

    /** @param array<string,mixed> $checkpoint */
    public function checkpoint(AccountResearchAttempt $attempt, array $checkpoint): AccountResearchStage
    {
        $hash = $this->checkpointHash($checkpoint);

        return DB::transaction(function () use ($attempt, $checkpoint, $hash): AccountResearchStage {
            $run = AccountResearchRun::query()
                ->whereKey($attempt->research_run_id)
                ->where('workspace_id', $attempt->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();
            $stage = AccountResearchStage::query()
                ->whereKey($attempt->stage_id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAttempt = AccountResearchAttempt::query()
                ->whereKey($attempt->id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->where('stage_id', $stage->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertRunOpen($run);

            return $this->checkpointLocked($attempt, $lockedAttempt, $stage, $checkpoint, $hash);
        }, 3);
    }

    /**
     * Persist a provider result and complete its stage under one fence.
     *
     * The callback may call services which open nested transactions. Laravel
     * keeps those as savepoints on this connection, so a fence failure or
     * checkpoint error rolls back every result row written by the callback.
     *
     * @param  Closure():array<string,mixed>  $persist
     */
    public function persistAndCheckpoint(AccountResearchAttempt $attempt, Closure $persist): AccountResearchStage
    {
        return DB::transaction(function () use ($attempt, $persist): AccountResearchStage {
            $run = AccountResearchRun::query()
                ->whereKey($attempt->research_run_id)
                ->where('workspace_id', $attempt->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();
            $stage = AccountResearchStage::query()
                ->whereKey($attempt->stage_id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAttempt = AccountResearchAttempt::query()
                ->whereKey($attempt->id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->where('stage_id', $stage->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertRunOpen($run);
            if (! $this->ownsProviderFence($attempt, $lockedAttempt, $stage)) {
                throw new AccountResearchBusy('The Account research provider fence is no longer current.');
            }

            $checkpoint = $persist();
            $hash = $this->checkpointHash($checkpoint);

            // Refresh the locked rows so a deterministic test seam, or any
            // same-transaction fence transition, is checked before the
            // terminal stage/attempt mutation.
            $run->refresh();
            $stage->refresh();
            $lockedAttempt->refresh();
            $this->assertRunOpen($run);
            if (! $this->ownsProviderFence($attempt, $lockedAttempt, $stage)) {
                throw new AccountResearchBusy('The Account research provider fence changed while persisting its result.');
            }

            return $this->checkpointLocked($attempt, $lockedAttempt, $stage, $checkpoint, $hash);
        }, 3);
    }

    /**
     * Persist a local stage result and its checkpoint under one run/stage
     * fence. This is the non-provider counterpart to persistAndCheckpoint().
     *
     * @param  Closure(): array<string,mixed>  $persist
     */
    public function persistLocalAndCheckpoint(AccountResearchAttempt $attempt, Closure $persist): AccountResearchStage
    {
        return DB::transaction(function () use ($attempt, $persist): AccountResearchStage {
            $run = AccountResearchRun::query()
                ->whereKey($attempt->research_run_id)
                ->where('workspace_id', $attempt->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();
            $stage = AccountResearchStage::query()
                ->whereKey($attempt->stage_id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAttempt = AccountResearchAttempt::query()
                ->whereKey($attempt->id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->where('stage_id', $stage->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertRunOpen($run);
            if (! $this->ownsLocalFence($attempt, $lockedAttempt, $stage)) {
                throw new AccountResearchBusy('The Account research local persistence fence is no longer current.');
            }

            $checkpoint = $persist();
            $hash = $this->checkpointHash($checkpoint);

            $run->refresh();
            $stage->refresh();
            $lockedAttempt->refresh();
            $this->assertRunOpen($run);
            if (! $this->ownsLocalFence($attempt, $lockedAttempt, $stage)) {
                throw new AccountResearchBusy('The Account research local fence changed while persisting its result.');
            }

            return $this->checkpointLocked($attempt, $lockedAttempt, $stage, $checkpoint, $hash);
        }, 3);
    }

    /** @param array<string,mixed> $checkpoint */
    public function skip(
        AccountResearchRun $run,
        AccountResearchStage|string $stage,
        AccountResearchAttempt $attempt,
        array $checkpoint = [],
    ): AccountResearchStage {
        $stageId = $stage instanceof AccountResearchStage ? (string) $stage->id : $stage;
        $payload = ['skipped' => true, ...$checkpoint];
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return DB::transaction(function () use ($run, $stageId, $attempt, $payload, $encoded): AccountResearchStage {
            $lockedRun = AccountResearchRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $lockedStage = AccountResearchStage::query()
                ->whereKey($stageId)
                ->where('workspace_id', $lockedRun->workspace_id)
                ->where('research_run_id', $lockedRun->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAttempt = AccountResearchAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->first();
            if ($lockedAttempt === null
                || (string) $lockedAttempt->workspace_id !== (string) $lockedRun->workspace_id
                || (string) $lockedAttempt->research_run_id !== (string) $lockedRun->id
                || (string) $lockedAttempt->stage_id !== (string) $lockedStage->id) {
                throw new AccountResearchBusy('The Account research skip attempt is no longer current.');
            }
            // Validate the attempt/fence even for an idempotent terminal
            // replay. A stale worker may observe a completed stage, but it
            // must never be able to present an unrelated attempt as current.
            $this->assertFence($attempt, $lockedAttempt, $lockedStage);
            $stageState = ModelAttribute::enumValue($lockedStage->state);
            if ($stageState === AccountResearchStageState::Succeeded->value || $stageState === AccountResearchStageState::Skipped->value) {
                return $lockedStage->fresh();
            }
            $this->assertSkipLease($lockedAttempt, $lockedStage);
            $lockedStage->forceFill([
                'state' => AccountResearchStageState::Skipped,
                'checkpoint_reference' => $payload,
                'checkpoint_hash' => hash('sha256', $encoded),
                'lease_owner' => null,
                'lease_expires_at' => null,
                'succeeded_at' => now(),
                'failed_at' => null,
            ])->save();
            $lockedAttempt->forceFill([
                'state' => AccountResearchAttemptState::Succeeded,
                'outcome' => $payload,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ])->save();

            return $lockedStage->fresh();
        }, 3);
    }

    public function attachProviderOperation(AccountResearchAttempt $attempt, string $operationId): void
    {
        DB::transaction(function () use ($attempt, $operationId): void {
            $run = AccountResearchRun::query()
                ->whereKey($attempt->research_run_id)
                ->where('workspace_id', $attempt->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();
            $stage = AccountResearchStage::query()
                ->whereKey($attempt->stage_id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = AccountResearchAttempt::query()
                ->whereKey($attempt->id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->where('stage_id', $stage->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertRunAdmitsProviderWork($run);
            if (ModelAttribute::enumValue($stage->state) !== AccountResearchStageState::Running->value
                || ! $this->ownsStageFence($attempt, $locked, $stage)) {
                throw new AccountResearchBusy('The Account research provider attachment fence is no longer current.');
            }
            $this->assertFence($attempt, $locked, $stage);
            $operation = AiProviderOperation::query()
                ->where('workspace_id', $run->workspace_id)
                ->whereKey($operationId)
                ->where('idempotency_key', $locked->deterministic_idempotency_key)
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->provider_operation_id !== null && (string) $locked->provider_operation_id !== (string) $operation->id) {
                throw new RuntimeException('The attempt is already attached to another provider operation.');
            }
            $budgetColumn = $this->budgetColumn($operation);
            if ($run->{$budgetColumn} !== null && (string) $run->{$budgetColumn} !== (string) $operation->budget_run_id) {
                throw new RuntimeException('The provider operation belongs to a different research budget run.');
            }
            $locked->forceFill(['provider_operation_id' => $operation->id])->save();
            $run->forceFill([
                $budgetColumn => $run->{$budgetColumn} ?: $operation->budget_run_id,
            ])->save();
        }, 3);
    }

    public function deferForBudget(AccountResearchAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt): void {
            $run = AccountResearchRun::query()
                ->whereKey($attempt->research_run_id)
                ->where('workspace_id', $attempt->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();
            $stage = AccountResearchStage::query()
                ->whereKey($attempt->stage_id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = AccountResearchAttempt::query()
                ->whereKey($attempt->id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->where('stage_id', $stage->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertRunAdmitsProviderWork($run);
            $this->assertFence($attempt, $locked, $stage);
            if ($locked->provider_operation_id !== null) {
                throw new RuntimeException('An admitted provider operation cannot be deferred as a budget wait.');
            }
            $locked->forceFill([
                'state' => AccountResearchAttemptState::WaitingBudget,
                'safe_error_code' => 'budget_unavailable',
                'lease_expires_at' => null,
                'finished_at' => null,
            ])->save();
            $stage->forceFill(['state' => AccountResearchStageState::Pending, 'lease_owner' => null, 'lease_expires_at' => null])->save();
        }, 3);
    }

    public function fail(AccountResearchAttempt $attempt, string $safeErrorCode, AccountResearchAttemptState|string $state = AccountResearchAttemptState::Failed): bool
    {
        return DB::transaction(function () use ($attempt, $safeErrorCode, $state): bool {
            // Keep the same run -> stage -> attempt lock order as begin(). A
            // failure callback is allowed to mutate only the exact attempt
            // and fence that originally encountered the error.
            $run = AccountResearchRun::query()
                ->whereKey($attempt->research_run_id)
                ->where('workspace_id', $attempt->workspace_id)
                ->lockForUpdate()
                ->first();
            if ($run === null || AccountResearchRunState::tryFrom(ModelAttribute::enumValue($run->state) ?? '')?->terminal() === true) {
                return false;
            }
            $stage = AccountResearchStage::query()
                ->whereKey($attempt->stage_id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->lockForUpdate()
                ->first();
            if ($stage === null || $this->stageTerminal($stage)) {
                return false;
            }
            $locked = AccountResearchAttempt::query()
                ->whereKey($attempt->id)
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->where('stage_id', $stage->id)
                ->lockForUpdate()
                ->first();
            if ($locked === null || ! $this->ownsStageFence($attempt, $locked, $stage)) {
                return false;
            }

            $attemptState = $state instanceof AccountResearchAttemptState ? $state : AccountResearchAttemptState::from($state);
            $locked->forceFill(['state' => $attemptState, 'safe_error_code' => $safeErrorCode, 'lease_expires_at' => null, 'finished_at' => now()])->save();
            $stage->forceFill([
                'state' => $safeErrorCode === 'paid_result_lost' ? AccountResearchStageState::PaidResultLost : AccountResearchStageState::Failed,
                'safe_error_code' => $safeErrorCode,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'failed_at' => now(),
            ])->save();

            return true;
        }, 3);
    }

    private function assertHash(string $hash): void
    {
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw new RuntimeException('The Account research input hash is invalid.');
        }
    }

    private function assertFence(AccountResearchAttempt $supplied, AccountResearchAttempt $locked, AccountResearchStage $stage): void
    {
        $latest = AccountResearchAttempt::query()
            ->where('stage_id', $stage->id)
            ->orderByDesc('attempt_number')
            ->lockForUpdate()
            ->first();
        if ($latest === null
            || (string) $latest->id !== (string) $locked->id
            || ! is_string($supplied->lease_owner)
            || ! is_string($locked->lease_owner)
            || ! hash_equals($locked->lease_owner, $supplied->lease_owner)
            || (int) $supplied->fencing_token !== (int) $locked->fencing_token) {
            throw new AccountResearchBusy('The Account research fencing lease is no longer current.');
        }
    }

    private function ownsStageFence(AccountResearchAttempt $supplied, AccountResearchAttempt $locked, AccountResearchStage $stage): bool
    {
        $latestId = AccountResearchAttempt::query()
            ->where('workspace_id', $locked->workspace_id)
            ->where('research_run_id', $locked->research_run_id)
            ->where('stage_id', $stage->id)
            ->orderByDesc('attempt_number')
            ->lockForUpdate()
            ->value('id');

        return (string) $latestId === (string) $locked->id
            && is_string($supplied->lease_owner)
            && is_string($locked->lease_owner)
            && is_string($stage->lease_owner)
            && hash_equals($locked->lease_owner, $supplied->lease_owner)
            && hash_equals($stage->lease_owner, $locked->lease_owner)
            && (int) $supplied->fencing_token === (int) $locked->fencing_token
            && (int) $stage->fencing_token === (int) $locked->fencing_token;
    }

    private function ownsProviderFence(AccountResearchAttempt $supplied, AccountResearchAttempt $locked, AccountResearchStage $stage): bool
    {
        $attemptLease = ModelAttribute::instant($locked->lease_expires_at);
        $stageLease = ModelAttribute::instant($stage->lease_expires_at);

        return $this->ownsStageFence($supplied, $locked, $stage)
            && ModelAttribute::enumValue($locked->state) === AccountResearchAttemptState::ProviderBoundary->value
            && $attemptLease?->isFuture() === true
            && $stageLease?->isFuture() === true;
    }

    private function ownsLocalFence(AccountResearchAttempt $supplied, AccountResearchAttempt $locked, AccountResearchStage $stage): bool
    {
        $attemptLease = ModelAttribute::instant($locked->lease_expires_at);
        $stageLease = ModelAttribute::instant($stage->lease_expires_at);

        return $this->ownsStageFence($supplied, $locked, $stage)
            && ModelAttribute::enumValue($locked->state) === AccountResearchAttemptState::Running->value
            && $attemptLease?->isFuture() === true
            && $stageLease?->isFuture() === true;
    }

    /** @param array<string,mixed> $checkpoint */
    private function checkpointHash(array $checkpoint): string
    {
        $encoded = json_encode($checkpoint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > (int) config('intelligence.research.max_checkpoint_bytes', 512 * 1024)) {
            throw new RuntimeException('The Account research checkpoint exceeds its size limit.');
        }

        return hash('sha256', $encoded);
    }

    private function assertRunOpen(AccountResearchRun $run): void
    {
        $state = AccountResearchRunState::tryFrom(ModelAttribute::enumValue($run->state) ?? '');
        if ($state?->terminal() === true || $state === AccountResearchRunState::Cancelling) {
            throw new AccountResearchBusy('The Account research run no longer accepts stage results.');
        }
    }

    private function assertRunAdmitsProviderWork(AccountResearchRun $run): void
    {
        $state = AccountResearchRunState::tryFrom(ModelAttribute::enumValue($run->state) ?? '');
        if ($state?->terminal() === true || $state === AccountResearchRunState::Cancelling) {
            throw new AccountResearchBusy('The Account research run no longer admits provider work.');
        }
    }

    private function reclaimAttempt(
        AccountResearchRun $run,
        AccountResearchStage $stage,
        AccountResearchAttempt $attempt,
    ): AccountResearchAttempt {
        $fence = max((int) $stage->fencing_token, (int) $attempt->fencing_token) + 1;
        $leaseOwner = (string) Str::uuid();
        $leaseExpiresAt = now()->addSeconds($this->leaseSeconds());
        $attempt->forceFill([
            'state' => AccountResearchAttemptState::Running,
            'lease_owner' => $leaseOwner,
            'fencing_token' => $fence,
            'lease_expires_at' => $leaseExpiresAt,
            'safe_error_code' => null,
            'finished_at' => null,
        ])->save();
        $stage->forceFill([
            'state' => AccountResearchStageState::Running,
            'lease_owner' => $leaseOwner,
            'fencing_token' => $fence,
            'lease_expires_at' => $leaseExpiresAt,
            'safe_error_code' => null,
            'failed_at' => null,
        ])->save();
        $run->forceFill([
            'state' => AccountResearchRunState::Running,
            'current_stage' => $stage->stage_key,
            'awaiting_reason' => null,
            'finished_at' => null,
        ])->save();

        return $attempt->fresh();
    }

    /** @param array<string,mixed> $checkpoint */
    private function checkpointLocked(
        AccountResearchAttempt $supplied,
        AccountResearchAttempt $lockedAttempt,
        AccountResearchStage $stage,
        array $checkpoint,
        string $hash,
    ): AccountResearchStage {
        $this->assertFence($supplied, $lockedAttempt, $stage);
        $stageState = ModelAttribute::enumValue($stage->state);
        if ($stageState === AccountResearchStageState::Succeeded->value) {
            if ((string) $stage->checkpoint_hash !== $hash) {
                throw new RuntimeException('A successful checkpoint cannot be replaced.');
            }

            return $stage->fresh();
        }
        if ($stageState === AccountResearchStageState::Skipped->value) {
            throw new RuntimeException('A skipped stage cannot be checkpointed.');
        }
        $stage->forceFill([
            'state' => AccountResearchStageState::Succeeded,
            'checkpoint_reference' => $checkpoint,
            'checkpoint_hash' => $hash,
            'lease_expires_at' => null,
            'succeeded_at' => now(),
            'failed_at' => null,
        ])->save();
        $lockedAttempt->forceFill([
            'state' => AccountResearchAttemptState::Succeeded,
            'outcome' => $checkpoint,
            'lease_expires_at' => null,
            'finished_at' => now(),
            'safe_error_code' => null,
        ])->save();

        return $stage->fresh();
    }

    private function stageTerminal(AccountResearchStage $stage): bool
    {
        return in_array(ModelAttribute::enumValue($stage->state), [
            AccountResearchStageState::Succeeded->value,
            AccountResearchStageState::Skipped->value,
            AccountResearchStageState::Failed->value,
            AccountResearchStageState::PaidResultLost->value,
            AccountResearchStageState::Cancelled->value,
        ], true);
    }

    private function operationForAttempt(AccountResearchRun $run, AccountResearchAttempt $attempt): ?AiProviderOperation
    {
        $query = AiProviderOperation::query()->where('workspace_id', $run->workspace_id);
        if ($attempt->provider_operation_id !== null) {
            $operation = (clone $query)
                ->whereKey($attempt->provider_operation_id)
                ->lockForUpdate()
                ->first();
            if ($operation !== null) {
                if ((string) $operation->idempotency_key !== (string) $attempt->deterministic_idempotency_key) {
                    throw new RuntimeException('The attempt is attached to a different provider operation key.');
                }

                return $operation;
            }
        }

        return $query
            ->where('idempotency_key', $attempt->deterministic_idempotency_key)
            ->lockForUpdate()
            ->first();
    }

    private function budgetColumn(AiProviderOperation $operation): string
    {
        return ModelAttribute::enumValue($operation->task) === AiTask::Qualification->value
            ? 'qualification_budget_run_id'
            : 'research_budget_run_id';
    }

    private function operationRetryable(AiProviderOperation $operation): bool
    {
        return in_array($operation->status, [AiOperationStatus::Failed, AiOperationStatus::Cancelled], true);
    }

    private function markProviderUncertain(AccountResearchAttempt $attempt, AccountResearchStage $stage): void
    {
        $attemptState = ModelAttribute::enumValue($attempt->state);
        $fencingToken = $attemptState === AccountResearchAttemptState::Ambiguous->value
            ? (int) $attempt->fencing_token
            : (int) $attempt->fencing_token + 1;
        $attempt->forceFill([
            'state' => AccountResearchAttemptState::Ambiguous,
            'safe_error_code' => 'provider_uncertain',
            'fencing_token' => $fencingToken,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'finished_at' => null,
        ])->save();
        $stage->forceFill([
            'state' => AccountResearchStageState::Running,
            'safe_error_code' => 'provider_uncertain',
            'fencing_token' => $fencingToken,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'failed_at' => null,
        ])->save();
    }

    private function awaitProvider(AccountResearchRun $run): void
    {
        $run->forceFill([
            'state' => AccountResearchRunState::AwaitingProvider,
            'awaiting_reason' => 'provider',
            'finished_at' => null,
        ])->save();
    }

    private function finishRunForLostResult(AccountResearchRun $run): void
    {
        $usable = ResearchSourceSnapshot::query()
            ->where('workspace_id', $run->workspace_id)
            ->where('research_run_id', $run->id)
            ->whereIn('state', ['available', 'partial'])
            ->exists()
            || ResearchClaim::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('research_run_id', $run->id)
                ->exists();
        $run->forceFill([
            'state' => $usable ? AccountResearchRunState::Partial : AccountResearchRunState::Failed,
            'safe_failure_code' => 'paid_result_lost',
            'current_stage' => null,
            'awaiting_reason' => null,
            'finished_at' => now(),
        ])->save();
    }

    private function assertSkipLease(AccountResearchAttempt $locked, AccountResearchStage $stage): void
    {
        $attemptState = ModelAttribute::enumValue($locked->state);
        $leaseExpiresAt = ModelAttribute::instant($locked->lease_expires_at);
        $stageLeaseExpiresAt = ModelAttribute::instant($stage->lease_expires_at);
        $stageOwnerMatches = is_string($locked->lease_owner)
            && is_string($stage->lease_owner)
            && hash_equals($stage->lease_owner, $locked->lease_owner);

        if ($attemptState !== AccountResearchAttemptState::Running->value
            || ! $this->leaseFresh($locked)
            || $leaseExpiresAt === null
            || ! $leaseExpiresAt->isFuture()
            || $stageLeaseExpiresAt === null
            || ! $stageLeaseExpiresAt->isFuture()
            || ! $stageOwnerMatches
            || (int) $stage->fencing_token !== (int) $locked->fencing_token) {
            throw new AccountResearchBusy('The Account research skip lease is no longer current.');
        }
    }

    private function leaseFresh(AccountResearchAttempt $attempt): bool
    {
        return ModelAttribute::instant($attempt->lease_expires_at)?->isFuture() ?? false;
    }

    private function leaseSeconds(): int
    {
        return max(1, (int) config('intelligence.research.attempt_lease_seconds', config('gtm.research.attempt_lease_seconds', 300)));
    }

    private function providerLeaseSeconds(): int
    {
        return max($this->leaseSeconds(), (int) config('intelligence.research.provider_lease_seconds', 960));
    }

    private function idempotencyKey(AccountResearchRun $run, AccountResearchStage $stage, int $attemptNumber): string
    {
        return 'account-research:'.$run->workspace_id.':'.$run->id.':'.(ModelAttribute::enumValue($stage->stage_key) ?? '').':'.$attemptNumber;
    }
}
