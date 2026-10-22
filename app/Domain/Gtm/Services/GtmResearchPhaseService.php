<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Gtm\Enums\GtmProfileResearchState;
use App\Domain\Gtm\Enums\GtmResearchPhaseStatus;
use App\Domain\Gtm\Enums\GtmResearchRunState;
use App\Domain\Gtm\Exceptions\GtmResearchPaidResultLost;
use App\Domain\Gtm\Exceptions\GtmResearchPhaseBusy;
use App\Domain\Gtm\Exceptions\GtmResearchReconciliationPending;
use App\Domain\Gtm\Exceptions\GtmResearchRetryExhausted;
use App\Models\AiProviderOperation;
use App\Models\GtmProfile;
use App\Models\GtmProfileResearchAttempt;
use App\Models\GtmProfileResearchPhase;
use App\Models\GtmProfileResearchRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Owns durable phase checkpoints and the execution-attempt ledger.
 *
 * A provider response is never considered replayable until checkpoint() has
 * committed it. This makes the paid-result-loss boundary explicit instead of
 * accidentally repeating a billable operation with the same key.
 */
final class GtmResearchPhaseService
{
    public function __construct(private readonly AiBudgetGateway $budget) {}

    /**
     * @return array{attempt: GtmProfileResearchAttempt|null, checkpoint: array<string, mixed>|null}
     */
    public function begin(
        GtmProfileResearchRun $run,
        string $phaseName,
        string $inputHash,
    ): array {
        if (preg_match('/^[0-9a-f]{64}$/', $inputHash) !== 1) {
            throw new RuntimeException('The research phase input hash is invalid.');
        }

        $result = DB::transaction(function () use ($run, $phaseName, $inputHash): array {
            $lockedRun = GtmProfileResearchRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $profile = GtmProfile::query()->whereKey($lockedRun->gtm_profile_id)->lockForUpdate()->firstOrFail();
            if ((string) $profile->current_research_run_id !== (string) $lockedRun->id) {
                throw new GtmResearchPhaseBusy('The research run has been superseded.');
            }

            $phase = GtmProfileResearchPhase::query()
                ->where('research_run_id', $lockedRun->id)
                ->where('phase', $phaseName)
                ->lockForUpdate()
                ->first();

            if ($phase === null) {
                $phase = GtmProfileResearchPhase::query()->create([
                    'research_run_id' => $lockedRun->id,
                    'phase' => $phaseName,
                    'status' => GtmResearchPhaseStatus::Pending,
                ]);
            }

            if (in_array($phase->status->value, [
                GtmResearchPhaseStatus::Succeeded->value,
                GtmResearchPhaseStatus::Skipped->value,
            ], true)) {
                return [
                    'attempt' => null,
                    'checkpoint' => is_array($phase->successful_checkpoint)
                        ? $phase->successful_checkpoint
                        : [],
                ];
            }

            $latest = GtmProfileResearchAttempt::query()
                ->where('phase_id', $phase->id)
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();
            if ($latest !== null) {
                $decision = $this->guardLatestAttempt($lockedRun, $profile, $phase, $latest);
                if ($decision !== null) {
                    return $decision;
                }
            }

            $attemptNumber = $latest === null ? 1 : (int) $latest->attempt_number + 1;
            $maximumAttempts = max(1, (int) config('gtm.research.max_attempts', 3));
            if ($attemptNumber > $maximumAttempts) {
                throw new GtmResearchRetryExhausted('The research phase exhausted its retry limit.');
            }
            $fencingToken = max(
                $latest === null ? 0 : (int) $latest->fencing_token,
                $latest === null ? 0 : (int) $latest->attempt_number,
            ) + 1;

            $attempt = GtmProfileResearchAttempt::query()->create([
                'phase_id' => $phase->id,
                'research_run_id' => $lockedRun->id,
                'attempt_number' => $attemptNumber,
                'deterministic_idempotency_key' => $this->idempotencyKey($lockedRun, $phaseName, $attemptNumber),
                'provider_operation_id' => null,
                'input_hash' => $inputHash,
                'status' => 'running',
                'lease_owner' => (string) Str::uuid(),
                'fencing_token' => $fencingToken,
                'lease_expires_at' => now()->addSeconds($this->attemptLeaseSeconds()),
                'outcome' => null,
                'safe_error_code' => null,
                'started_at' => now(),
                'finished_at' => null,
            ]);

            $phase->forceFill([
                'status' => GtmResearchPhaseStatus::Running,
                'started_at' => $phase->started_at ?? now(),
                'failed_at' => null,
            ])->save();

            return ['attempt' => $attempt, 'checkpoint' => null, 'decision' => 'execute'];
        }, 3);

        $decision = $result['decision'] ?? 'execute';
        $attempt = $result['attempt'] ?? null;
        if ($decision === 'busy') {
            throw new GtmResearchPhaseBusy('The research phase already has active provider work.');
        }
        if ($decision === 'reconcile') {
            throw new GtmResearchReconciliationPending('The research phase is waiting for provider reconciliation.');
        }
        if ($decision === 'paid_result_lost') {
            $this->failPaidResultLost($attempt instanceof GtmProfileResearchAttempt ? $attempt : null);

            throw new GtmResearchPaidResultLost('The settled provider result has no durable research checkpoint.');
        }

        return [
            'attempt' => $attempt instanceof GtmProfileResearchAttempt ? $attempt : null,
            'checkpoint' => $result['checkpoint'] ?? null,
        ];
    }

    /**
     * Persist a bounded, sanitized phase result and mark its attempt complete.
     *
     * @param  array<string, mixed>  $checkpoint
     */
    public function checkpoint(
        GtmProfileResearchAttempt $attempt,
        array $checkpoint,
    ): GtmProfileResearchPhase {
        $encoded = json_encode($checkpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > (int) config('gtm.research.max_checkpoint_bytes', 512 * 1024)) {
            throw new RuntimeException('The research checkpoint exceeds the bounded size limit.');
        }
        $hash = hash('sha256', $encoded);

        return DB::transaction(function () use ($attempt, $checkpoint, $hash): GtmProfileResearchPhase {
            $lockedAttempt = GtmProfileResearchAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $run = GtmProfileResearchRun::query()->whereKey($lockedAttempt->research_run_id)->lockForUpdate()->firstOrFail();
            $profile = GtmProfile::query()->whereKey($run->gtm_profile_id)->lockForUpdate()->firstOrFail();
            if ((string) $profile->current_research_run_id !== (string) $run->id) {
                throw new GtmResearchPhaseBusy('The research run has been superseded.');
            }
            $phase = GtmProfileResearchPhase::query()->whereKey($lockedAttempt->phase_id)->lockForUpdate()->firstOrFail();
            $this->assertLatestFence($attempt, $lockedAttempt, $phase);
            if ($phase->status->value === GtmResearchPhaseStatus::Succeeded->value) {
                if ((string) $phase->checkpoint_hash !== $hash) {
                    throw new RuntimeException('The research phase checkpoint cannot be replaced.');
                }

                return $phase->fresh();
            }
            if ($phase->status->value === GtmResearchPhaseStatus::Skipped->value) {
                throw new RuntimeException('A skipped research phase cannot be checkpointed.');
            }
            $this->assertActiveProviderFence($lockedAttempt);

            $phase->forceFill([
                'status' => GtmResearchPhaseStatus::Succeeded,
                'successful_checkpoint' => $checkpoint,
                'checkpoint_hash' => $hash,
                'succeeded_at' => now(),
                'failed_at' => null,
            ])->save();
            $lockedAttempt->forceFill([
                'status' => 'succeeded',
                'outcome' => $checkpoint,
                'safe_error_code' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ])->save();

            return $phase->fresh();
        }, 3);
    }

    /**
     * Atomically cross the provider boundary for the current fenced worker.
     * Once claimed, a replacement attempt cannot be issued until this longer
     * provider lease expires or AF-01 supplies a durable operation state.
     */
    public function claimProviderBoundary(
        GtmProfileResearchAttempt $attempt,
    ): GtmProfileResearchAttempt {
        return DB::transaction(function () use ($attempt): GtmProfileResearchAttempt {
            $lockedAttempt = GtmProfileResearchAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();
            $run = GtmProfileResearchRun::query()
                ->whereKey($lockedAttempt->research_run_id)
                ->lockForUpdate()
                ->firstOrFail();
            $profile = GtmProfile::query()->whereKey($run->gtm_profile_id)->lockForUpdate()->firstOrFail();
            if ((string) $profile->current_research_run_id !== (string) $run->id) {
                throw new GtmResearchPhaseBusy('The research run has been superseded.');
            }
            $phase = GtmProfileResearchPhase::query()
                ->whereKey($lockedAttempt->phase_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertLatestFence($attempt, $lockedAttempt, $phase);
            if ((string) $lockedAttempt->status !== 'running') {
                throw new GtmResearchPhaseBusy('The research attempt no longer owns the provider boundary.');
            }
            $this->assertUnexpiredLease($lockedAttempt);

            $lockedAttempt->forceFill([
                'status' => 'provider_boundary',
                'lease_expires_at' => now()->addSeconds($this->providerLeaseSeconds()),
            ])->save();

            return $lockedAttempt->fresh();
        }, 3);
    }

    /**
     * Persist that a settled response was successfully inspected but rejected
     * by the bounded output contract. No provider bytes are retained.
     */
    public function recordInspectedFailure(
        GtmProfileResearchAttempt $attempt,
        string $safeErrorCode = 'output_invalid',
    ): void {
        if ($safeErrorCode !== 'output_invalid') {
            throw new RuntimeException('The inspected research failure code is invalid.');
        }
        $marker = [
            'type' => 'inspected_failure',
            'response_inspected' => true,
            'safe_error_code' => $safeErrorCode,
        ];
        $encoded = json_encode($marker, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > (int) config('gtm.research.max_checkpoint_bytes', 512 * 1024)) {
            throw new RuntimeException('The inspected research failure marker exceeds the bounded size limit.');
        }

        DB::transaction(function () use ($attempt, $marker, $safeErrorCode): void {
            $lockedAttempt = GtmProfileResearchAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();
            $run = GtmProfileResearchRun::query()
                ->whereKey($lockedAttempt->research_run_id)
                ->lockForUpdate()
                ->firstOrFail();
            $profile = GtmProfile::query()->whereKey($run->gtm_profile_id)->lockForUpdate()->firstOrFail();
            if ((string) $profile->current_research_run_id !== (string) $run->id) {
                throw new GtmResearchPhaseBusy('The research run has been superseded.');
            }
            $phase = GtmProfileResearchPhase::query()
                ->whereKey($lockedAttempt->phase_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertLatestFence($attempt, $lockedAttempt, $phase);
            $this->assertActiveProviderFence($lockedAttempt);

            $operation = $this->operationForAttempt($lockedAttempt, $profile->workspace_id, lock: true);
            if ($operation === null || ! in_array($operation->status, [
                AiOperationStatus::Succeeded,
                AiOperationStatus::Reconciled,
                AiOperationStatus::ManualReview,
            ], true)) {
                throw new RuntimeException('The inspected provider response is not durably settled.');
            }

            $lockedAttempt->forceFill([
                'provider_operation_id' => $operation->id,
                'status' => 'failed',
                'outcome' => $marker,
                'safe_error_code' => $safeErrorCode,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ])->save();
            $phase->forceFill([
                'status' => GtmResearchPhaseStatus::Failed,
                'failed_at' => now(),
            ])->save();
            $run->forceFill([
                'state' => GtmResearchRunState::Failed,
                'awaiting_reason' => null,
                'safe_failure_code' => $safeErrorCode,
                'failed_at' => now(),
                'finished_at' => now(),
            ])->save();
            $profile->forceFill([
                'research_state' => GtmProfileResearchState::Failed,
                'awaiting_reason' => null,
                'last_research_failed_at' => now(),
                'safe_error_code' => $safeErrorCode,
            ])->save();
        }, 3);
    }

    /**
     * Record a deliberate skip, such as the conditional search phase.
     *
     * @param  array<string, mixed>  $checkpoint
     */
    public function skip(GtmProfileResearchRun $run, string $phaseName, array $checkpoint = []): GtmProfileResearchPhase
    {
        $checkpoint = ['skipped' => true, ...$checkpoint];
        $encoded = json_encode($checkpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > (int) config('gtm.research.max_checkpoint_bytes', 512 * 1024)) {
            throw new RuntimeException('The research checkpoint exceeds the bounded size limit.');
        }

        return DB::transaction(function () use ($run, $phaseName, $checkpoint, $encoded): GtmProfileResearchPhase {
            $lockedRun = GtmProfileResearchRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $profile = GtmProfile::query()->whereKey($lockedRun->gtm_profile_id)->lockForUpdate()->firstOrFail();
            if ((string) $profile->current_research_run_id !== (string) $lockedRun->id) {
                throw new GtmResearchPhaseBusy('The research run has been superseded.');
            }
            $phase = GtmProfileResearchPhase::query()
                ->where('research_run_id', $lockedRun->id)
                ->where('phase', $phaseName)
                ->lockForUpdate()
                ->firstOrCreate([], [
                    'research_run_id' => $run->id,
                    'phase' => $phaseName,
                    'status' => GtmResearchPhaseStatus::Pending,
                ]);
            if (in_array($phase->status->value, [
                GtmResearchPhaseStatus::Succeeded->value,
                GtmResearchPhaseStatus::Skipped->value,
            ], true)) {
                return $phase->fresh();
            }

            $phase->forceFill([
                'status' => GtmResearchPhaseStatus::Skipped,
                'successful_checkpoint' => $checkpoint,
                'checkpoint_hash' => hash('sha256', $encoded),
                'succeeded_at' => now(),
            ])->save();

            return $phase->fresh();
        }, 3);
    }

    public function fail(
        GtmProfileResearchAttempt $attempt,
        string $safeErrorCode,
        string $status = 'failed',
    ): void {
        DB::transaction(function () use ($attempt, $safeErrorCode, $status): void {
            $lockedAttempt = GtmProfileResearchAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();
            if ($lockedAttempt === null) {
                return;
            }
            $phase = GtmProfileResearchPhase::query()->whereKey($lockedAttempt->phase_id)->lockForUpdate()->first();
            if ($phase === null || $phase->status->value === GtmResearchPhaseStatus::Succeeded->value) {
                return;
            }
            $latestAttemptId = GtmProfileResearchAttempt::query()
                ->where('phase_id', $phase->id)
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->value('id');
            $lockedAttempt->forceFill([
                'status' => $status,
                'safe_error_code' => $safeErrorCode,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ])->save();
            if ((int) $latestAttemptId === (int) $lockedAttempt->id) {
                $phase->forceFill([
                    'status' => GtmResearchPhaseStatus::Failed,
                    'failed_at' => now(),
                ])->save();
            }
        }, 3);
    }

    /**
     * A budget rejection happens before provider work. Keep the same durable
     * attempt/key available for the next admission check instead of spending a
     * provider retry from the bounded attempt allowance.
     */
    public function deferForBudget(GtmProfileResearchAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt): void {
            $lockedAttempt = GtmProfileResearchAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();
            if ($lockedAttempt === null) {
                return;
            }
            $phase = GtmProfileResearchPhase::query()->whereKey($lockedAttempt->phase_id)->lockForUpdate()->first();
            if ($phase === null || in_array($phase->status->value, [
                GtmResearchPhaseStatus::Succeeded->value,
                GtmResearchPhaseStatus::Skipped->value,
            ], true)) {
                return;
            }
            $this->assertLatestFence($attempt, $lockedAttempt, $phase);
            if (! in_array((string) $lockedAttempt->status, ['running', 'provider_boundary'], true)) {
                throw new GtmResearchPhaseBusy('The research attempt no longer owns its budget decision.');
            }
            $this->assertUnexpiredLease($lockedAttempt);

            $operation = $this->operationForAttempt($lockedAttempt);
            if ($operation !== null) {
                throw new RuntimeException('A provider operation cannot be deferred as a budget wait.');
            }

            $lockedAttempt->forceFill([
                'status' => 'waiting_budget',
                'safe_error_code' => 'budget_unavailable',
                'lease_expires_at' => null,
                'finished_at' => null,
            ])->save();
            $phase->forceFill([
                'status' => GtmResearchPhaseStatus::Pending,
                'failed_at' => null,
            ])->save();
        }, 3);
    }

    public function attachProviderOperation(GtmProfileResearchAttempt $attempt, string $operationId): void
    {
        DB::transaction(function () use ($attempt, $operationId): void {
            $lockedAttempt = GtmProfileResearchAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $run = GtmProfileResearchRun::query()->whereKey($lockedAttempt->research_run_id)->lockForUpdate()->firstOrFail();
            $profile = GtmProfile::query()->whereKey($run->gtm_profile_id)->lockForUpdate()->firstOrFail();
            if ((string) $profile->current_research_run_id !== (string) $run->id) {
                throw new GtmResearchPhaseBusy('The research run has been superseded.');
            }
            $operation = AiProviderOperation::query()
                ->whereKey($operationId)
                ->where('workspace_id', $profile->workspace_id)
                ->where('idempotency_key', $lockedAttempt->deterministic_idempotency_key)
                ->lockForUpdate()
                ->firstOrFail();
            if ($run->budget_run_id !== null && (string) $run->budget_run_id !== (string) $operation->budget_run_id) {
                throw new RuntimeException('The provider operation belongs to a different budget run.');
            }
            if ($run->budget_run_id === null) {
                $run->forceFill(['budget_run_id' => $operation->budget_run_id])->save();
            }
            if ($lockedAttempt->provider_operation_id !== null
                && (string) $lockedAttempt->provider_operation_id !== (string) $operation->id) {
                throw new RuntimeException('The research attempt is already attached to another provider operation.');
            }
            $lockedAttempt->forceFill([
                'provider_operation_id' => $operation->id,
            ])->save();
        }, 3);
    }

    /**
     * Classify an unexpected phase failure from AF-01's durable operation
     * state before deciding whether the phase may ever receive another key.
     * A settled result without a checkpoint is paid-result-loss; a call that
     * may have reached the provider must reconcile; only a released/absent
     * operation is an ordinary retryable failure.
     */
    public function classifyFailure(
        GtmProfileResearchAttempt $attempt,
        string $fallbackSafeCode = 'research_failed',
    ): void {
        $operation = $this->operationForAttempt($attempt);
        if ($operation === null) {
            $this->fail($attempt, $fallbackSafeCode);

            return;
        }

        $this->attachProviderOperation($attempt, (string) $operation->id);
        $operation->refresh();

        if (in_array($operation->status, [
            AiOperationStatus::Succeeded,
            AiOperationStatus::Reconciled,
            AiOperationStatus::ManualReview,
        ], true)) {
            $this->markPaidResultLost($attempt);
        }

        if ($operation->status === AiOperationStatus::Executing) {
            $operation = $this->budget->markUncertain(
                $operation,
                'unknown_provider_failure',
                'Provider execution ended without a durable research checkpoint.',
            );
            if (in_array($operation->status, [
                AiOperationStatus::Succeeded,
                AiOperationStatus::Reconciled,
                AiOperationStatus::ManualReview,
            ], true)) {
                $this->markPaidResultLost($attempt);
            }
        }

        if (in_array($operation->status, [
            AiOperationStatus::Uncertain,
            AiOperationStatus::Reconciling,
        ], true)) {
            $this->markReconciliationPending($attempt);

            throw new GtmResearchReconciliationPending('The provider operation requires reconciliation.');
        }

        if (in_array($operation->status, [AiOperationStatus::Planned, AiOperationStatus::Reserved], true)) {
            $operation = $this->budget->releaseDefinitelyNotSent(
                $operation,
                'worker_interrupted_before_call',
                'Research failed before the provider request was sent.',
            );
            if (in_array($operation->status, [
                AiOperationStatus::Succeeded,
                AiOperationStatus::Reconciled,
                AiOperationStatus::ManualReview,
            ], true)) {
                $this->markPaidResultLost($attempt);
            }
        }

        $this->fail($attempt, $fallbackSafeCode);
    }

    /**
     * Determine whether a running orchestration has outlived all local/AF-01
     * leases and needs its durable dispatch repaired by the sweeper.
     */
    public function recoveryDue(GtmProfileResearchRun $run): bool
    {
        $attempt = GtmProfileResearchAttempt::query()
            ->where('research_run_id', $run->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if ($attempt === null) {
            return ! $this->dateIsFresh($run->updated_at ?? $run->started_at ?? $run->created_at);
        }
        if ((string) $attempt->safe_error_code === 'paid_result_lost') {
            return true;
        }
        if (! in_array((string) $attempt->status, ['running', 'provider_boundary', 'planned', 'ambiguous', 'waiting_budget'], true)) {
            return ! $this->dateIsFresh($attempt->updated_at ?? $attempt->finished_at ?? $attempt->started_at);
        }

        $operation = $this->operationForAttempt($attempt);
        if ($operation === null) {
            return ! $this->attemptIsFresh($attempt);
        }
        if (in_array($operation->status, [AiOperationStatus::Uncertain, AiOperationStatus::Reconciling], true)) {
            return false;
        }
        if (in_array($operation->status, [
            AiOperationStatus::Succeeded,
            AiOperationStatus::Reconciled,
            AiOperationStatus::ManualReview,
            AiOperationStatus::Failed,
            AiOperationStatus::Cancelled,
        ], true)) {
            return true;
        }

        return ! $this->operationIsFresh($operation);
    }

    public function markPaidResultLost(GtmProfileResearchAttempt $attempt): never
    {
        $this->fail($attempt, 'paid_result_lost', 'failed');

        throw new GtmResearchPaidResultLost('The provider result was settled but its checkpoint was not persisted.');
    }

    private function failPaidResultLost(?GtmProfileResearchAttempt $attempt): void
    {
        if ($attempt !== null) {
            $this->fail($attempt, 'paid_result_lost', 'failed');
        }
    }

    private function markReconciliationPending(GtmProfileResearchAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt): void {
            $lockedAttempt = GtmProfileResearchAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();
            if ($lockedAttempt === null) {
                return;
            }
            $phase = GtmProfileResearchPhase::query()->whereKey($lockedAttempt->phase_id)->lockForUpdate()->first();
            if ($phase === null || in_array($phase->status->value, [
                GtmResearchPhaseStatus::Succeeded->value,
                GtmResearchPhaseStatus::Skipped->value,
            ], true)) {
                return;
            }

            $lockedAttempt->forceFill([
                'status' => 'ambiguous',
                'safe_error_code' => 'provider_uncertain',
                'lease_expires_at' => null,
                'finished_at' => null,
            ])->save();
            $phase->forceFill([
                'status' => GtmResearchPhaseStatus::Running,
                'failed_at' => null,
            ])->save();
        }, 3);
    }

    /**
     * @return array{attempt: GtmProfileResearchAttempt, checkpoint: null, decision: string}|null
     */
    private function guardLatestAttempt(
        GtmProfileResearchRun $run,
        GtmProfile $profile,
        GtmProfileResearchPhase $phase,
        GtmProfileResearchAttempt $attempt,
    ): ?array {
        // Settled provider work without a durable checkpoint is terminal for
        // this logical run. Its failed local status must never make begin()
        // allocate a new attempt/key and accidentally repeat paid work.
        if ((string) $attempt->safe_error_code === 'paid_result_lost') {
            return ['attempt' => $attempt, 'checkpoint' => null, 'decision' => 'paid_result_lost'];
        }
        if ($this->isInspectedFailure($attempt)) {
            return null;
        }

        $activeAttempt = in_array(
            (string) $attempt->status,
            ['running', 'provider_boundary', 'planned', 'ambiguous', 'waiting_budget'],
            true,
        );
        $operation = $this->operationForAttempt($attempt, $profile->workspace_id, lock: true);
        if ($operation !== null) {
            if ($run->budget_run_id !== null && (string) $run->budget_run_id !== (string) $operation->budget_run_id) {
                throw new RuntimeException('The recovered provider operation belongs to a different budget run.');
            }
            if ($run->budget_run_id === null) {
                $run->forceFill(['budget_run_id' => $operation->budget_run_id])->save();
            }
            if ((string) $attempt->provider_operation_id !== (string) $operation->id) {
                $attempt->forceFill(['provider_operation_id' => $operation->id])->save();
            }
        }

        if ($operation === null) {
            if (! $activeAttempt) {
                return null;
            }
            if ((string) $attempt->status === 'waiting_budget') {
                $attempt->forceFill([
                    'status' => 'running',
                    'lease_owner' => (string) Str::uuid(),
                    'fencing_token' => (int) $attempt->fencing_token + 1,
                    'lease_expires_at' => now()->addSeconds($this->attemptLeaseSeconds()),
                    'safe_error_code' => null,
                    'started_at' => now(),
                    'finished_at' => null,
                ])->save();
                $phase->forceFill([
                    'status' => GtmResearchPhaseStatus::Running,
                    'started_at' => $phase->started_at ?? now(),
                    'failed_at' => null,
                ])->save();

                return ['attempt' => $attempt->fresh(), 'checkpoint' => null, 'decision' => 'execute'];
            }
            if ($this->attemptIsFresh($attempt)) {
                return [
                    'attempt' => $attempt,
                    'checkpoint' => null,
                    'decision' => (string) $attempt->status === 'ambiguous' ? 'reconcile' : 'busy',
                ];
            }

            // AF-01 always creates its durable reservation before making a
            // network call. Once the local attempt lease expires, absence of
            // that reservation is therefore definitely-not-sent.
            $attempt->forceFill([
                'status' => 'definitely_not_sent',
                'safe_error_code' => 'worker_interrupted_before_call',
                'lease_expires_at' => null,
                'finished_at' => now(),
            ])->save();

            return null;
        }

        if (in_array($operation->status, [AiOperationStatus::Uncertain, AiOperationStatus::Reconciling], true)) {
            $attempt->forceFill(['status' => 'ambiguous', 'lease_expires_at' => null])->save();

            return ['attempt' => $attempt->fresh(), 'checkpoint' => null, 'decision' => 'reconcile'];
        }
        if ($operation->status === AiOperationStatus::Executing) {
            if ($this->attemptIsFresh($attempt) || $this->operationIsFresh($operation)) {
                return ['attempt' => $attempt, 'checkpoint' => null, 'decision' => 'busy'];
            }

            // A stale executing operation may have reached the provider. Move
            // it under AF-01 reconciliation; never repeat the original call.
            $this->budget->markUncertain(
                $operation,
                'worker_interrupted_after_call',
                'Provider execution outlived its worker lease and requires reconciliation.',
            );
            $attempt->forceFill([
                'status' => 'ambiguous',
                'safe_error_code' => 'provider_uncertain',
                'lease_expires_at' => null,
            ])->save();

            return ['attempt' => $attempt->fresh(), 'checkpoint' => null, 'decision' => 'reconcile'];
        }
        if (in_array($operation->status, [AiOperationStatus::Planned, AiOperationStatus::Reserved], true)) {
            if ($this->attemptIsFresh($attempt) || $this->operationIsFresh($operation)) {
                return ['attempt' => $attempt, 'checkpoint' => null, 'decision' => 'busy'];
            }

            $this->budget->releaseDefinitelyNotSent(
                $operation,
                'worker_interrupted_before_call',
                'The provider reservation expired before network execution.',
            );
            $attempt->forceFill([
                'status' => 'definitely_not_sent',
                'safe_error_code' => 'worker_interrupted_before_call',
                'lease_expires_at' => null,
                'finished_at' => now(),
            ])->save();

            return null;
        }
        if (in_array($operation->status, [
            AiOperationStatus::Succeeded,
            AiOperationStatus::Reconciled,
            AiOperationStatus::ManualReview,
        ], true)) {
            return ['attempt' => $attempt, 'checkpoint' => null, 'decision' => 'paid_result_lost'];
        }

        $attempt->forceFill([
            'status' => 'definitely_not_sent',
            'safe_error_code' => 'provider_operation_failed',
            'lease_expires_at' => null,
            'finished_at' => now(),
        ])->save();

        return null;
    }

    private function operationForAttempt(
        GtmProfileResearchAttempt $attempt,
        ?int $workspaceId = null,
        bool $lock = false,
    ): ?AiProviderOperation {
        if ($workspaceId === null) {
            $rawWorkspaceId = GtmProfileResearchRun::query()
                ->join('gtm_profiles', 'gtm_profiles.id', '=', 'gtm_profile_research_runs.gtm_profile_id')
                ->where('gtm_profile_research_runs.id', $attempt->research_run_id)
                ->value('gtm_profiles.workspace_id');
            $workspaceId = is_numeric($rawWorkspaceId) ? (int) $rawWorkspaceId : null;
        }
        if (! is_int($workspaceId)) {
            return null;
        }

        $query = AiProviderOperation::query()->where('workspace_id', $workspaceId);
        if ($attempt->provider_operation_id !== null) {
            $query->whereKey($attempt->provider_operation_id);
        } else {
            $query->where('idempotency_key', $attempt->deterministic_idempotency_key);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function assertLatestFence(
        GtmProfileResearchAttempt $supplied,
        GtmProfileResearchAttempt $locked,
        GtmProfileResearchPhase $phase,
    ): void {
        $latest = GtmProfileResearchAttempt::query()
            ->where('phase_id', $phase->id)
            ->orderByDesc('attempt_number')
            ->lockForUpdate()
            ->first();
        $suppliedOwner = $supplied->lease_owner;
        $lockedOwner = $locked->lease_owner;
        if ($latest === null
            || (int) $latest->id !== (int) $locked->id
            || ! is_string($suppliedOwner)
            || $suppliedOwner === ''
            || ! is_string($lockedOwner)
            || ! hash_equals($lockedOwner, $suppliedOwner)
            || (int) $supplied->fencing_token !== (int) $locked->fencing_token) {
            throw new GtmResearchPhaseBusy('The research attempt fencing lease is no longer current.');
        }
    }

    private function assertActiveProviderFence(GtmProfileResearchAttempt $attempt): void
    {
        if ((string) $attempt->status !== 'provider_boundary') {
            throw new GtmResearchPhaseBusy('The research attempt does not own the provider boundary.');
        }

        $this->assertUnexpiredLease($attempt);
    }

    private function assertUnexpiredLease(GtmProfileResearchAttempt $attempt): void
    {
        if (! $this->leaseIsFuture($attempt)) {
            throw new GtmResearchPhaseBusy('The research attempt fencing lease has expired.');
        }
    }

    private function isInspectedFailure(GtmProfileResearchAttempt $attempt): bool
    {
        $outcome = $attempt->getAttribute('outcome');

        return is_array($outcome)
            && ($outcome['type'] ?? null) === 'inspected_failure'
            && ($outcome['response_inspected'] ?? null) === true
            && ($outcome['safe_error_code'] ?? null) === 'output_invalid';
    }

    private function attemptIsFresh(GtmProfileResearchAttempt $attempt): bool
    {
        if ($attempt->getAttribute('lease_expires_at') !== null) {
            return $this->leaseIsFuture($attempt);
        }

        return $this->dateIsFresh($attempt->started_at ?? $attempt->created_at);
    }

    private function leaseIsFuture(GtmProfileResearchAttempt $attempt): bool
    {
        $value = $attempt->getAttribute('lease_expires_at');
        if ($value === null) {
            return false;
        }

        try {
            return CarbonImmutable::parse($value)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    private function operationIsFresh(AiProviderOperation $operation): bool
    {
        if ($operation->lease_expires_at !== null && $operation->lease_expires_at->isFuture()) {
            return true;
        }

        return $this->dateIsFresh($operation->started_at ?? $operation->reserved_at ?? $operation->updated_at);
    }

    private function dateIsFresh(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        try {
            return CarbonImmutable::parse($value)
                ->addSeconds($this->attemptLeaseSeconds())
                ->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    private function attemptLeaseSeconds(): int
    {
        return max(1, (int) config('gtm.research.attempt_lease_seconds', 300));
    }

    private function providerLeaseSeconds(): int
    {
        return max(
            $this->attemptLeaseSeconds(),
            max(60, (int) config('gtm.research.timeout_seconds', 900)) + 60,
        );
    }

    private function idempotencyKey(GtmProfileResearchRun $run, string $phaseName, int $attemptNumber): string
    {
        return 'gtm-research:'.$run->gtm_profile_id.':'.$run->id.':'.$phaseName.':'.$attemptNumber;
    }
}
