<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Exceptions\AiBudgetExceeded;
use App\Domain\Ai\Exceptions\AiIdempotencyConflict;
use App\Domain\Ai\Exceptions\AiTaskPolicyMismatch;
use App\Models\AiBudgetEnvelope;
use App\Models\AiBudgetPeriod;
use App\Models\AiBudgetRun;
use App\Models\AiBudgetSetting;
use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialVersion;
use App\Models\AiProviderOperation;
use App\Models\AiTaskSetting;
use App\Models\AiUsageLedger;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The sole monetary boundary for AI provider calls.
 *
 * Counter updates always lock the current monthly period, then the optional
 * workflow envelope, then the task run, and finally the provider operation.
 * This order is shared by reserve/settle/release/cancel paths to keep MySQL
 * workers from deadlocking while preserving SQLite compatibility.
 */
class AiBudgetGateway
{
    /**
     * Advisory, side-effect-free admission for an orchestration step.
     */
    public function mayAdmit(
        Workspace $workspace,
        AiTask $task,
        int $costMicros,
        ?AiBudgetRun $run = null,
        ?AiBudgetEnvelope $budgetEnvelope = null,
    ): bool {
        if ($costMicros < 0) {
            return false;
        }

        $settings = AiBudgetSetting::query()->whereKey($workspace->id)->first();
        $taskSetting = AiTaskSetting::query()
            ->where('workspace_id', $workspace->id)
            ->where('task', $task->value)
            ->first();
        if ($settings === null || ($task !== AiTask::ConnectionTest && $taskSetting === null)) {
            return false;
        }

        $start = Date::now('UTC')->startOfMonth()->toDateString();
        $period = AiBudgetPeriod::query()
            ->where('workspace_id', $workspace->id)
            ->whereDate('period_start_utc', $start)
            ->first();
        $monthlyLimit = $period === null
            ? (int) $settings->monthly_limit_micros
            : (int) $period->limit_snapshot_micros;
        $monthlyUsed = $period === null
            ? 0
            : (int) $period->committed_micros + (int) $period->held_micros;
        if ($monthlyLimit <= 0
            || ($period !== null && ($period->budget_breach || $period->over_limit))
            || $monthlyUsed + $costMicros > $monthlyLimit) {
            return false;
        }

        if ($run === null) {
            $runLimit = $task === AiTask::ConnectionTest
                ? (int) config('ai.setup_test_limit_micros')
                : (int) $taskSetting->per_run_limit_micros;
            if ($runLimit <= 0 || $costMicros > $runLimit) {
                return false;
            }
        } else {
            $freshRun = AiBudgetRun::query()
                ->whereKey($run->id)
                ->where('workspace_id', $workspace->id)
                ->first();
            if ($freshRun === null
                || $freshRun->task !== $task
                || ! in_array($freshRun->status, ['open'], true)
                || $freshRun->closed_at !== null
                || (int) $freshRun->limit_snapshot_micros <= 0
                || (int) $freshRun->committed_micros + (int) $freshRun->held_micros + $costMicros
                    > (int) $freshRun->limit_snapshot_micros) {
                return false;
            }
            if ($budgetEnvelope === null && $freshRun->budget_envelope_id !== null) {
                $budgetEnvelope = AiBudgetEnvelope::query()->whereKey($freshRun->budget_envelope_id)->first();
            }
        }

        return $budgetEnvelope === null
            || ((int) $budgetEnvelope->hard_limit_micros > 0
                && $budgetEnvelope->state === 'open'
                && $budgetEnvelope->closed_at === null
                && (int) $budgetEnvelope->committed_micros + (int) $budgetEnvelope->held_micros + $costMicros
                    <= (int) $budgetEnvelope->hard_limit_micros);
    }

    /**
     * Advisory admission for a batch that will use one task run per item.
     *
     * The authoritative boundary remains reserve(), called for every item in
     * one database transaction. This check gives orchestration preflight the
     * same monthly and per-run arithmetic without incorrectly applying one
     * arm's run limit to the aggregate of all arms.
     *
     * @param  list<int>  $costsMicros
     */
    public function mayAdmitBatch(
        Workspace $workspace,
        AiTask $task,
        array $costsMicros,
        ?int $envelopeLimitMicros = null,
    ): bool {
        if ($costsMicros === [] || collect($costsMicros)->contains(fn (int $cost): bool => $cost < 0)) {
            return false;
        }

        $settings = AiBudgetSetting::query()->whereKey($workspace->id)->first();
        $taskSetting = AiTaskSetting::query()
            ->where('workspace_id', $workspace->id)
            ->where('task', $task->value)
            ->first();
        if ($settings === null || ($task !== AiTask::ConnectionTest && $taskSetting === null)) {
            return false;
        }

        $total = array_sum($costsMicros);
        $runLimit = $task === AiTask::ConnectionTest
            ? (int) config('ai.setup_test_limit_micros')
            : (int) $taskSetting->per_run_limit_micros;
        if ($runLimit <= 0 || collect($costsMicros)->contains(fn (int $cost): bool => $cost > $runLimit)) {
            return false;
        }
        if ($envelopeLimitMicros !== null && ($envelopeLimitMicros <= 0 || $total > $envelopeLimitMicros)) {
            return false;
        }

        $start = Date::now('UTC')->startOfMonth()->toDateString();
        $period = AiBudgetPeriod::query()
            ->where('workspace_id', $workspace->id)
            ->whereDate('period_start_utc', $start)
            ->first();
        $monthlyLimit = $period === null
            ? (int) $settings->monthly_limit_micros
            : (int) $period->limit_snapshot_micros;
        $monthlyUsed = $period === null
            ? 0
            : (int) $period->committed_micros + (int) $period->held_micros;

        return $monthlyLimit > 0
            && ($period === null || (! $period->budget_breach && ! $period->over_limit))
            && $monthlyUsed + $total <= $monthlyLimit;
    }

    /**
     * Open an idempotent workflow-level budget envelope. The owner key is an
     * opaque product key (for example an S2 research run UUID), never an
     * account identifier or user-provided secret.
     */
    public function openEnvelope(
        Workspace $workspace,
        ?User $initiator,
        string $kind,
        string $ownerKey,
        int $hardLimitMicros,
        string $currency = 'USD',
        ?int $pricingVersion = null,
        ?string $catalogueVersion = null,
    ): AiBudgetEnvelope {
        $this->assertInitiator($workspace, $initiator);
        if ($kind === '' || $ownerKey === '' || $hardLimitMicros < 0) {
            throw new RuntimeException('The AI budget envelope is outside the bounded policy.');
        }

        return DB::transaction(function () use ($workspace, $kind, $ownerKey, $hardLimitMicros, $currency, $pricingVersion, $catalogueVersion): AiBudgetEnvelope {
            $existing = AiBudgetEnvelope::query()
                ->where('workspace_id', $workspace->id)
                ->where('kind', $kind)
                ->where('owner_key', $ownerKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ((int) $existing->hard_limit_micros !== $hardLimitMicros || (string) $existing->currency !== $currency) {
                    throw new AiIdempotencyConflict('This budget envelope key was used with a different hard maximum.');
                }

                return $existing->fresh();
            }

            $settings = AiBudgetSetting::query()->whereKey($workspace->id)->first();

            return AiBudgetEnvelope::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'kind' => $kind,
                'owner_key' => $ownerKey,
                'hard_limit_micros' => $hardLimitMicros,
                'currency' => $currency,
                'pricing_version' => $pricingVersion ?? (int) ($settings->version ?? 1),
                'catalogue_version' => $catalogueVersion ?? config('ai.catalogue_version', '2026-09-03'),
                'state' => 'open',
                'opened_at' => now(),
            ]);
        }, 3);
    }

    /** Alias retained for callers that use the aggregate name. */
    public function openBudgetEnvelope(
        Workspace $workspace,
        ?User $initiator,
        string $kind,
        string $ownerKey,
        int $hardLimitMicros,
        string $currency = 'USD',
        ?int $pricingVersion = null,
        ?string $catalogueVersion = null,
    ): AiBudgetEnvelope {
        return $this->openEnvelope(
            $workspace,
            $initiator,
            $kind,
            $ownerKey,
            $hardLimitMicros,
            $currency,
            $pricingVersion,
            $catalogueVersion,
        );
    }

    public function openRun(
        Workspace $workspace,
        ?User $initiator,
        AiTask $task,
        AiBudgetEnvelope|string|null $budgetEnvelope = null,
    ): AiBudgetRun {
        $this->assertInitiator($workspace, $initiator);
        $envelopeId = $this->envelopeId($workspace, $budgetEnvelope);

        return DB::transaction(function () use ($workspace, $initiator, $task, $envelopeId): AiBudgetRun {
            if ($envelopeId !== null) {
                $envelope = AiBudgetEnvelope::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($envelopeId)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($envelope->state !== 'open' || $envelope->closed_at !== null) {
                    throw new RuntimeException('The AI budget envelope is not open.');
                }
            }

            return AiBudgetRun::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'task' => $task,
                'budget_envelope_id' => $envelopeId,
                'initiated_by_user_id' => $initiator?->id,
                'limit_snapshot_micros' => $this->runLimit($workspace, $task),
                'status' => 'open',
                'orchestration_owned' => true,
                'opened_at' => now(),
            ]);
        }, 3);
    }

    public function reserve(
        Workspace $workspace,
        AiProviderCredential $credential,
        AiProviderCredentialVersion $version,
        ?User $initiator,
        AiTask $task,
        string $operation,
        string $modelOrEndpoint,
        int $nativeUnits,
        int $costMicros,
        string $idempotencyKey,
        string $requestFingerprint,
        ?string $runId = null,
        ?string $budgetEnvelopeId = null,
        ?int $requiredPolicyVersion = null,
    ): AiProviderOperation {
        if ($costMicros < 0 || $nativeUnits < 0) {
            throw new RuntimeException('Cost reservations cannot be negative.');
        }
        if ($idempotencyKey === '' || $requestFingerprint === '') {
            throw new RuntimeException('AI provider operations require an idempotency key and fingerprint.');
        }
        $this->assertInitiator($workspace, $initiator);

        return DB::transaction(function () use ($workspace, $credential, $version, $initiator, $task, $operation, $modelOrEndpoint, $nativeUnits, $costMicros, $idempotencyKey, $requestFingerprint, $runId, $budgetEnvelopeId, $requiredPolicyVersion): AiProviderOperation {
            // Durable idempotency is checked before any new budget row is
            // touched, so replay remains side-effect free.
            $existing = AiProviderOperation::query()
                ->where('workspace_id', $workspace->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_fingerprint, $requestFingerprint)) {
                    throw new AiIdempotencyConflict('This idempotency key was already used for a different request policy.');
                }
                if ($requiredPolicyVersion !== null && $task !== AiTask::ConnectionTest) {
                    $currentTaskSetting = AiTaskSetting::query()
                        ->where('workspace_id', $workspace->id)
                        ->where('task', $task->value)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $this->assertTaskPolicy(
                        $currentTaskSetting,
                        (string) $existing->capability,
                        $modelOrEndpoint,
                        $task,
                        $requiredPolicyVersion,
                    );
                }

                return $existing->fresh();
            }

            $lockedCredential = AiProviderCredential::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($credential->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $version->credential_id !== (int) $lockedCredential->id || $version->wiped_at !== null) {
                throw new RuntimeException('The credential version is not available for reservation.');
            }

            $taskSetting = null;
            if ($task !== AiTask::ConnectionTest) {
                if ($lockedCredential->status->value !== 'healthy' || (int) $lockedCredential->active_version_id !== (int) $version->id) {
                    throw new RuntimeException('The active provider credential is not healthy.');
                }
                $taskSetting = AiTaskSetting::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('task', $task->value)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertTaskPolicy(
                    $taskSetting,
                    (string) $lockedCredential->capability,
                    $modelOrEndpoint,
                    $task,
                    $requiredPolicyVersion,
                );
            }

            $settings = AiBudgetSetting::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $start = Date::now('UTC')->startOfMonth()->toDateString();
            $end = Date::now('UTC')->addMonthNoOverflow()->startOfMonth()->toDateString();
            DB::table('ai_budget_periods')->insertOrIgnore([
                'workspace_id' => $workspace->id,
                'period_start_utc' => $start,
                'period_end_utc' => $end,
                'limit_snapshot_micros' => $settings->monthly_limit_micros,
                'held_micros' => 0,
                'committed_micros' => 0,
                'over_limit' => false,
                'budget_breach' => false,
                'settings_version' => $settings->version,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            // First counter lock: monthly period.
            $period = AiBudgetPeriod::query()
                ->where('workspace_id', $workspace->id)
                ->whereDate('period_start_utc', $start)
                ->lockForUpdate()
                ->firstOrFail();

            // Probe the run only to learn its envelope without locking it out
            // of order; the authoritative lock follows the envelope lock.
            $runProbe = $runId === null
                ? null
                : AiBudgetRun::query()->where('workspace_id', $workspace->id)->whereKey($runId)->firstOrFail();
            $resolvedEnvelopeId = $budgetEnvelopeId ?? $runProbe?->budget_envelope_id;
            if ($runProbe !== null && $budgetEnvelopeId !== null && $runProbe->budget_envelope_id !== $budgetEnvelopeId) {
                throw new RuntimeException('The task run does not belong to the supplied budget envelope.');
            }

            // Second counter lock: workflow envelope.
            $envelope = $resolvedEnvelopeId === null
                ? null
                : AiBudgetEnvelope::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($resolvedEnvelopeId)
                    ->lockForUpdate()
                    ->firstOrFail();
            if ($envelope !== null && ($envelope->state !== 'open' || $envelope->closed_at !== null)) {
                throw new AiBudgetExceeded('The AI budget envelope cannot admit this operation.');
            }

            // Third counter lock: task run.
            if ($runId === null) {
                $run = AiBudgetRun::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'task' => $task,
                    'budget_envelope_id' => $envelope?->id,
                    'initiated_by_user_id' => $initiator?->id,
                    'limit_snapshot_micros' => $task === AiTask::ConnectionTest
                        ? (int) config('ai.setup_test_limit_micros')
                        : (int) $taskSetting->per_run_limit_micros,
                    'status' => 'open',
                    'orchestration_owned' => false,
                    'opened_at' => now(),
                ]);
            } else {
                $run = AiBudgetRun::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($runId)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($run->task !== $task || $run->status !== 'open' || $run->closed_at !== null) {
                    throw new RuntimeException('The budget run cannot admit this task operation.');
                }
                if ($envelope !== null && $run->budget_envelope_id !== $envelope->id) {
                    throw new RuntimeException('The task run does not belong to the supplied budget envelope.');
                }
            }

            $monthlyCommitted = (int) $period->committed_micros + (int) $period->held_micros;
            $runCommitted = (int) $run->committed_micros + (int) $run->held_micros;
            if ($period->budget_breach || $period->over_limit || $monthlyCommitted + $costMicros > (int) $period->limit_snapshot_micros) {
                throw new AiBudgetExceeded('The workspace monthly AI budget cannot admit this operation.');
            }
            if ((int) $run->limit_snapshot_micros <= 0 || $runCommitted + $costMicros > (int) $run->limit_snapshot_micros) {
                throw new AiBudgetExceeded('The task run limit cannot admit this operation.');
            }
            if ($envelope !== null
                && ((int) $envelope->hard_limit_micros <= 0
                    || (int) $envelope->committed_micros + (int) $envelope->held_micros + $costMicros
                        > (int) $envelope->hard_limit_micros)) {
                throw new AiBudgetExceeded('The AI budget envelope hard maximum cannot admit this operation.');
            }

            $period->forceFill(['held_micros' => (int) $period->held_micros + $costMicros])->save();
            $run->forceFill(['held_micros' => (int) $run->held_micros + $costMicros])->save();
            if ($envelope !== null) {
                $envelope->forceFill(['held_micros' => (int) $envelope->held_micros + $costMicros])->save();
            }

            return AiProviderOperation::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'budget_period_id' => $period->id,
                'budget_run_id' => $run->id,
                'budget_envelope_id' => $envelope?->id,
                'credential_id' => $lockedCredential->id,
                'credential_version_id' => $version->id,
                'provider' => $lockedCredential->provider,
                'capability' => $lockedCredential->capability,
                'task' => $task,
                'operation' => $operation,
                'initiated_by_user_id' => $initiator?->id,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $requestFingerprint,
                'model_or_endpoint' => $modelOrEndpoint,
                'policy_version' => $task === AiTask::ConnectionTest ? 1 : (int) $taskSetting->policy_version,
                'pricing_version' => (int) $settings->version,
                'status' => AiOperationStatus::Reserved,
                'reserved_native_units' => $nativeUnits,
                'reserved_cost_micros' => $costMicros,
                'provider_metadata' => $lockedCredential->provider->value === 'tavily'
                    ? ['credit_price_micros' => $nativeUnits > 0 ? intdiv($costMicros, $nativeUnits) : (int) config('ai.tavily_default_micros_per_credit')]
                    : null,
                'fencing_token' => 0,
                'reserved_at' => now(),
            ]);
        }, 3);
    }

    private function assertTaskPolicy(
        AiTaskSetting $taskSetting,
        string $capability,
        string $modelOrEndpoint,
        AiTask $task,
        ?int $requiredPolicyVersion,
    ): void {
        if ($capability === 'llm' && ((string) $taskSetting->model_id !== $modelOrEndpoint
            || ($requiredPolicyVersion !== null && (int) $taskSetting->policy_version !== $requiredPolicyVersion))) {
            if ($requiredPolicyVersion !== null) {
                throw new AiTaskPolicyMismatch;
            }

            throw new RuntimeException('The requested model does not match the active task policy.');
        }
        if ($capability === 'research'
            && ($task !== AiTask::AccountResearch || ! in_array($modelOrEndpoint, ['tavily/search', 'tavily/extract'], true))) {
            throw new RuntimeException('The research endpoint does not match an active task policy.');
        }
    }

    /** @param array<string, mixed> $usage */
    public function settle(
        AiProviderOperation $operation,
        int $actualCostMicros,
        int $actualNativeUnits,
        string $costBasis,
        array $usage = [],
        AiOperationStatus $finalStatus = AiOperationStatus::Succeeded,
        ?string $leaseOwner = null,
        ?int $fencingToken = null,
    ): AiProviderOperation {
        return $this->settleInternal(
            $operation,
            $actualCostMicros,
            $actualNativeUnits,
            $costBasis,
            $usage,
            $finalStatus,
            $leaseOwner,
            $fencingToken,
        )['operation'];
    }

    /**
     * Settle only when this caller still owns the exact execution fence.
     *
     * Unlike settle(), a terminal or fenced operation is not treated as an
     * idempotent success here. A worker must have proof that its own lease and
     * fencing token performed the settlement before it can expose a result.
     *
     * @param  array<string, mixed>  $usage
     */
    public function settleExact(
        AiProviderOperation $operation,
        int $actualCostMicros,
        int $actualNativeUnits,
        string $costBasis,
        array $usage = [],
        AiOperationStatus $finalStatus = AiOperationStatus::Succeeded,
        ?string $leaseOwner = null,
        ?int $fencingToken = null,
    ): ?AiProviderOperation {
        if ($leaseOwner === null || $fencingToken === null) {
            throw new RuntimeException('Exact AI settlement requires an execution lease and fencing token.');
        }

        $settlement = $this->settleInternal(
            $operation,
            $actualCostMicros,
            $actualNativeUnits,
            $costBasis,
            $usage,
            $finalStatus,
            $leaseOwner,
            $fencingToken,
        );

        return $settlement['applied'] ? $settlement['operation'] : null;
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array{operation: AiProviderOperation, applied: bool}
     */
    private function settleInternal(
        AiProviderOperation $operation,
        int $actualCostMicros,
        int $actualNativeUnits,
        string $costBasis,
        array $usage = [],
        AiOperationStatus $finalStatus = AiOperationStatus::Succeeded,
        ?string $leaseOwner = null,
        ?int $fencingToken = null,
    ): array {
        if ($actualCostMicros < 0 || $actualNativeUnits < 0 || ! $finalStatus->terminal()) {
            throw new RuntimeException('AI settlement must be non-negative and terminal.');
        }

        return DB::transaction(function () use ($operation, $actualCostMicros, $actualNativeUnits, $costBasis, $usage, $finalStatus, $leaseOwner, $fencingToken): array {
            $current = AiProviderOperation::query()->whereKey($operation->id)->firstOrFail();
            if ($current->status->terminal()) {
                return ['operation' => $current, 'applied' => false];
            }
            if ($leaseOwner !== null && ((string) $current->lease_owner !== $leaseOwner || (int) $current->fencing_token !== $fencingToken)) {
                return ['operation' => $current, 'applied' => false];
            }

            // Counter locks precede the final operation lock.
            $period = AiBudgetPeriod::query()->whereKey($current->budget_period_id)->lockForUpdate()->firstOrFail();
            $envelope = $current->budget_envelope_id === null
                ? null
                : AiBudgetEnvelope::query()->whereKey($current->budget_envelope_id)->lockForUpdate()->firstOrFail();
            $run = AiBudgetRun::query()->whereKey($current->budget_run_id)->lockForUpdate()->firstOrFail();
            $locked = AiProviderOperation::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->terminal()) {
                return ['operation' => $locked->fresh(), 'applied' => false];
            }
            if ($leaseOwner !== null && ((string) $locked->lease_owner !== $leaseOwner || (int) $locked->fencing_token !== $fencingToken)) {
                return ['operation' => $locked->fresh(), 'applied' => false];
            }

            $reserved = (int) $locked->reserved_cost_micros;
            $periodCommitted = (int) $period->committed_micros + $actualCostMicros;
            $period->forceFill([
                'held_micros' => max(0, (int) $period->held_micros - $reserved),
                'committed_micros' => $periodCommitted,
                'over_limit' => $periodCommitted > (int) $period->limit_snapshot_micros,
                'budget_breach' => $period->budget_breach || $actualCostMicros > $reserved,
            ])->save();
            if ($envelope !== null) {
                $envelopeCommitted = (int) $envelope->committed_micros + $actualCostMicros;
                $envelope->forceFill([
                    'held_micros' => max(0, (int) $envelope->held_micros - $reserved),
                    'committed_micros' => $envelopeCommitted,
                    'state' => $envelopeCommitted > (int) $envelope->hard_limit_micros ? 'breached' : $envelope->state,
                ])->save();
            }

            $runAttributes = [
                'held_micros' => max(0, (int) $run->held_micros - $reserved),
                'committed_micros' => (int) $run->committed_micros + $actualCostMicros,
            ];
            if ($run->shouldAutoClose()) {
                $runAttributes['status'] = $actualCostMicros > $reserved ? 'breached' : 'closed';
                $runAttributes['closed_at'] = now();
            } elseif ($actualCostMicros > $reserved) {
                $runAttributes['status'] = 'breached';
            }
            $run->forceFill($runAttributes)->save();

            $safeRequestId = $usage['safe_request_id']
                ?? $usage['provider_request_id']
                ?? $locked->safe_request_id
                ?? $locked->provider_request_id;
            $providerResponseId = $usage['provider_response_id'] ?? $locked->provider_response_id;
            $providerMetadata = $usage['provider_metadata'] ?? null;
            if ($providerMetadata !== null && ! is_array($providerMetadata)) {
                $providerMetadata = null;
            }
            if ($providerMetadata !== null) {
                $providerMetadata = array_merge(
                    is_array($locked->provider_metadata) ? $locked->provider_metadata : [],
                    $providerMetadata,
                );
            } else {
                $providerMetadata = $locked->provider_metadata;
            }
            $locked->forceFill([
                'status' => $finalStatus,
                'actual_cost_micros' => $actualCostMicros,
                'actual_native_units' => $actualNativeUnits,
                'cost_basis' => $costBasis,
                'provider_request_id' => $usage['provider_request_id'] ?? $locked->provider_request_id,
                'provider_generation_id' => $usage['provider_generation_id'] ?? $locked->provider_generation_id,
                'safe_request_id' => is_string($safeRequestId) ? mb_substr($safeRequestId, 0, 160) : $locked->safe_request_id,
                'provider_response_id' => is_string($providerResponseId) ? mb_substr($providerResponseId, 0, 160) : $locked->provider_response_id,
                'provider_metadata' => $providerMetadata,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ])->save();

            AiUsageLedger::query()->create([
                'workspace_id' => $locked->workspace_id,
                'operation_id' => $locked->id,
                'budget_period_id' => $period->id,
                'budget_run_id' => $run->id,
                'budget_envelope_id' => $envelope?->id,
                'provider' => $locked->provider,
                'operation' => $locked->operation,
                'task' => $locked->task,
                'model_or_endpoint' => $locked->model_or_endpoint,
                'entry_type' => 'charge',
                'native_unit' => $usage['native_unit'] ?? ($locked->provider->value === 'tavily' ? 'credits' : 'tokens'),
                'input_tokens' => $this->nullableInt($usage['input_tokens'] ?? null),
                'output_tokens' => $this->nullableInt($usage['output_tokens'] ?? null),
                'reasoning_tokens' => $this->nullableInt($usage['reasoning_tokens'] ?? null),
                'cache_tokens' => $this->nullableInt($usage['cache_tokens'] ?? null),
                'tavily_credits' => $locked->provider->value === 'tavily' ? $actualNativeUnits : null,
                'raw_provider_cost' => is_string($usage['raw_provider_cost'] ?? null) ? mb_substr($usage['raw_provider_cost'], 0, 120) : null,
                'cost_micros' => $actualCostMicros,
                'cost_basis' => $costBasis,
                'cost_evidence_source' => $usage['cost_evidence_source'] ?? $this->defaultEvidenceSource($costBasis),
                'provider_request_id' => $usage['provider_request_id'] ?? $locked->provider_request_id,
                'provider_generation_id' => $usage['provider_generation_id'] ?? $locked->provider_generation_id,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return ['operation' => $locked->fresh(), 'applied' => true];
        }, 3);
    }

    /** @param array<string, bool|int|string|null> $safeMetadata */
    public function releaseDefinitelyNotSent(
        AiProviderOperation $operation,
        string $safeCode,
        string $safeMessage,
        ?string $leaseOwner = null,
        ?int $fencingToken = null,
        ?int $retryAfterSeconds = null,
        array $safeMetadata = [],
    ): AiProviderOperation {
        return DB::transaction(function () use ($operation, $safeCode, $safeMessage, $leaseOwner, $fencingToken, $retryAfterSeconds, $safeMetadata): AiProviderOperation {
            $current = AiProviderOperation::query()->whereKey($operation->id)->firstOrFail();
            if ($current->status->terminal()) {
                return $current;
            }
            if ($leaseOwner !== null && ((string) $current->lease_owner !== $leaseOwner || (int) $current->fencing_token !== $fencingToken)) {
                return $current;
            }
            $period = AiBudgetPeriod::query()->whereKey($current->budget_period_id)->lockForUpdate()->firstOrFail();
            $envelope = $current->budget_envelope_id === null
                ? null
                : AiBudgetEnvelope::query()->whereKey($current->budget_envelope_id)->lockForUpdate()->firstOrFail();
            $run = AiBudgetRun::query()->whereKey($current->budget_run_id)->lockForUpdate()->firstOrFail();
            $locked = AiProviderOperation::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->terminal()) {
                return $locked->fresh();
            }
            if ($leaseOwner !== null && ((string) $locked->lease_owner !== $leaseOwner || (int) $locked->fencing_token !== $fencingToken)) {
                return $locked->fresh();
            }

            $reserved = (int) $locked->reserved_cost_micros;
            $period->forceFill(['held_micros' => max(0, (int) $period->held_micros - $reserved)])->save();
            if ($envelope !== null) {
                $envelope->forceFill(['held_micros' => max(0, (int) $envelope->held_micros - $reserved)])->save();
            }
            $runAttributes = ['held_micros' => max(0, (int) $run->held_micros - $reserved)];
            if ($run->shouldAutoClose()) {
                $runAttributes['status'] = 'closed';
                $runAttributes['closed_at'] = now();
            }
            $run->forceFill($runAttributes)->save();
            $locked->forceFill([
                'status' => AiOperationStatus::Failed,
                'safe_error_code' => mb_substr($safeCode, 0, 80),
                'safe_error_message' => mb_substr($safeMessage, 0, 500),
                'cost_basis' => 'not_charged',
                'actual_cost_micros' => 0,
                'actual_native_units' => 0,
                'retry_after_at' => $retryAfterSeconds === null
                    ? null
                    : now()->addSeconds(max(1, min(86_400, $retryAfterSeconds))),
                'provider_metadata' => $safeMetadata === []
                    ? $locked->provider_metadata
                    : array_merge(is_array($locked->provider_metadata) ? $locked->provider_metadata : [], $safeMetadata),
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ])->save();

            return $locked->fresh();
        }, 3);
    }

    /**
     * Release a pre-call hold while the caller owns its transaction. The
     * operation supplied here is expected to be the current locked row.
     */
    public function releaseDefinitelyNotSentWithinTransaction(
        AiProviderOperation $locked,
        string $safeCode,
        string $safeMessage,
    ): AiProviderOperation {
        if ($locked->status->terminal()) {
            return $locked;
        }
        $period = AiBudgetPeriod::query()->whereKey($locked->budget_period_id)->lockForUpdate()->firstOrFail();
        $envelope = $locked->budget_envelope_id === null
            ? null
            : AiBudgetEnvelope::query()->whereKey($locked->budget_envelope_id)->lockForUpdate()->firstOrFail();
        $run = AiBudgetRun::query()->whereKey($locked->budget_run_id)->lockForUpdate()->firstOrFail();
        $reserved = (int) $locked->reserved_cost_micros;
        $period->forceFill(['held_micros' => max(0, (int) $period->held_micros - $reserved)])->save();
        if ($envelope !== null) {
            $envelope->forceFill(['held_micros' => max(0, (int) $envelope->held_micros - $reserved)])->save();
        }
        $runAttributes = ['held_micros' => max(0, (int) $run->held_micros - $reserved)];
        if ($run->shouldAutoClose()) {
            $runAttributes['status'] = 'closed';
            $runAttributes['closed_at'] = now();
        }
        $run->forceFill($runAttributes)->save();
        $locked->forceFill([
            'status' => AiOperationStatus::Failed,
            'safe_error_code' => mb_substr($safeCode, 0, 80),
            'safe_error_message' => mb_substr($safeMessage, 0, 500),
            'cost_basis' => 'not_charged',
            'actual_cost_micros' => 0,
            'actual_native_units' => 0,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'finished_at' => now(),
        ])->save();

        return $locked->fresh();
    }

    public function closeRun(AiBudgetRun $run): AiBudgetRun
    {
        return DB::transaction(function () use ($run): AiBudgetRun {
            $source = AiBudgetRun::query()->whereKey($run->id)->firstOrFail();
            $periodIds = AiProviderOperation::query()->where('budget_run_id', $source->id)->pluck('budget_period_id')->filter()->unique()->sort()->values();
            foreach ($periodIds as $periodId) {
                AiBudgetPeriod::query()->whereKey($periodId)->lockForUpdate()->first();
            }
            if ($source->budget_envelope_id !== null) {
                AiBudgetEnvelope::query()->whereKey($source->budget_envelope_id)->lockForUpdate()->firstOrFail();
            }
            $locked = AiBudgetRun::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $operations = AiProviderOperation::query()->where('budget_run_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            if ($locked->closed_at !== null || in_array($locked->status, ['closed', 'cancelled'], true)) {
                return $locked->fresh();
            }
            if (! in_array($locked->status, ['open', 'breached'], true)) {
                throw new RuntimeException('The budget run cannot be closed from its current state.');
            }
            if ((int) $locked->held_micros > 0 || $operations->contains(fn (AiProviderOperation $item): bool => ! $item->status->terminal())) {
                throw new RuntimeException('The budget run still has unsettled operations.');
            }
            $locked->forceFill([
                'status' => $locked->status === 'breached' ? 'breached' : 'closed',
                'closed_at' => now(),
            ])->save();

            return $locked->fresh();
        }, 3);
    }

    public function closeEnvelope(AiBudgetEnvelope $envelope): AiBudgetEnvelope
    {
        return DB::transaction(function () use ($envelope): AiBudgetEnvelope {
            $source = AiBudgetEnvelope::query()->whereKey($envelope->id)->firstOrFail();
            $operations = AiProviderOperation::query()->where('budget_envelope_id', $source->id)->get();
            foreach ($operations->pluck('budget_period_id')->filter()->unique()->sort() as $periodId) {
                AiBudgetPeriod::query()->whereKey($periodId)->lockForUpdate()->first();
            }
            $locked = AiBudgetEnvelope::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $runs = AiBudgetRun::query()->where('budget_envelope_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $operations = AiProviderOperation::query()->where('budget_envelope_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            if ($locked->closed_at !== null || in_array($locked->state, ['closed', 'cancelled'], true)) {
                return $locked->fresh();
            }
            if ((int) $locked->held_micros > 0
                || $operations->contains(fn (AiProviderOperation $item): bool => ! $item->status->terminal())
                || $runs->contains(fn (AiBudgetRun $item): bool => (int) $item->held_micros > 0 || ! in_array($item->status, ['closed', 'cancelled', 'breached'], true))) {
                throw new RuntimeException('The AI budget envelope still has unsettled task runs or operations.');
            }
            $locked->forceFill([
                'state' => $locked->state === 'breached' ? 'breached' : 'closed',
                'closed_at' => now(),
            ])->save();

            return $locked->fresh();
        }, 3);
    }

    public function cancelRun(
        AiBudgetRun $run,
        string $safeCode = 'budget_run_cancelled',
        string $safeMessage = 'The budget run was cancelled before completion.',
    ): AiBudgetRun {
        return DB::transaction(function () use ($run, $safeCode, $safeMessage): AiBudgetRun {
            $source = AiBudgetRun::query()->whereKey($run->id)->firstOrFail();
            $periodIds = AiProviderOperation::query()->where('budget_run_id', $source->id)->pluck('budget_period_id')->filter()->unique()->sort()->values();
            foreach ($periodIds as $periodId) {
                AiBudgetPeriod::query()->whereKey($periodId)->lockForUpdate()->first();
            }
            if ($source->budget_envelope_id !== null) {
                $envelope = AiBudgetEnvelope::query()->whereKey($source->budget_envelope_id)->lockForUpdate()->firstOrFail();
            } else {
                $envelope = null;
            }
            $locked = AiBudgetRun::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $operations = AiProviderOperation::query()->where('budget_run_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            if ($locked->closed_at !== null || in_array($locked->status, ['closed', 'cancelled'], true)) {
                return $locked->fresh();
            }
            if (! in_array($locked->status, ['open', 'breached'], true)) {
                throw new RuntimeException('The budget run cannot be cancelled from its current state.');
            }
            $providerMayHaveBeenReached = [AiOperationStatus::Executing, AiOperationStatus::Uncertain, AiOperationStatus::Reconciling];
            if ($operations->contains(fn (AiProviderOperation $item): bool => in_array($item->status, $providerMayHaveBeenReached, true))) {
                throw new RuntimeException('The budget run has an operation that may have reached the provider.');
            }
            foreach ($operations as $operation) {
                if ($operation->status->terminal()) {
                    continue;
                }
                $reserved = (int) $operation->reserved_cost_micros;
                $period = $periodIds->contains($operation->budget_period_id)
                    ? AiBudgetPeriod::query()->whereKey($operation->budget_period_id)->first()
                    : null;
                if ($period !== null) {
                    $period->forceFill(['held_micros' => max(0, (int) $period->held_micros - $reserved)])->save();
                }
                if ($envelope !== null) {
                    $envelope->forceFill(['held_micros' => max(0, (int) $envelope->held_micros - $reserved)])->save();
                }
                $operation->forceFill([
                    'status' => AiOperationStatus::Cancelled,
                    'safe_error_code' => mb_substr($safeCode, 0, 80),
                    'safe_error_message' => mb_substr($safeMessage, 0, 500),
                    'cost_basis' => 'not_charged',
                    'actual_cost_micros' => 0,
                    'actual_native_units' => 0,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'finished_at' => now(),
                ])->save();
            }
            $locked->forceFill(['held_micros' => 0, 'status' => 'cancelled', 'closed_at' => now()])->save();

            return $locked->fresh();
        }, 3);
    }

    /** @param array<string, bool|int|string|null> $safeMetadata */
    public function markUncertain(
        AiProviderOperation $operation,
        string $safeCode,
        string $safeMessage,
        ?string $leaseOwner = null,
        ?int $fencingToken = null,
        ?int $retryAfterSeconds = null,
        array $safeMetadata = [],
    ): AiProviderOperation {
        return DB::transaction(function () use ($operation, $safeCode, $safeMessage, $leaseOwner, $fencingToken, $retryAfterSeconds, $safeMetadata): AiProviderOperation {
            $locked = AiProviderOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->terminal()) {
                return $locked->fresh();
            }
            if ($leaseOwner !== null && ((string) $locked->lease_owner !== $leaseOwner || (int) $locked->fencing_token !== $fencingToken)) {
                return $locked->fresh();
            }
            $configuredDelay = max(1, (int) config('ai.reconcile_after_minutes', 5)) * 60;
            $retryDelay = $retryAfterSeconds === null ? 0 : max(1, min(86_400, $retryAfterSeconds));
            $locked->forceFill([
                'status' => AiOperationStatus::Uncertain,
                'safe_error_code' => mb_substr($safeCode, 0, 80),
                'safe_error_message' => mb_substr($safeMessage, 0, 500),
                'reconcile_after' => now()->addSeconds(max($configuredDelay, $retryDelay)),
                'finalize_after' => $locked->finalize_after ?? now()->addHours(max(1, (int) config('ai.finalize_unknown_after_hours', 24))),
                'retry_after_at' => $retryAfterSeconds === null
                    ? null
                    : now()->addSeconds($retryDelay),
                'provider_metadata' => $safeMetadata === []
                    ? $locked->provider_metadata
                    : array_merge(is_array($locked->provider_metadata) ? $locked->provider_metadata : [], $safeMetadata),
                // Any transition out of executing fences the worker that
                // might still return from a timed-out provider call.
                'fencing_token' => (int) $locked->fencing_token + 1,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ])->save();

            return $locked->fresh();
        }, 3);
    }

    public function finalizeUncertain(AiProviderOperation $operation): AiProviderOperation
    {
        $fresh = $operation->fresh();
        if (! in_array($fresh->status, [AiOperationStatus::Uncertain, AiOperationStatus::Reconciling], true)) {
            return $fresh;
        }

        return $this->settle(
            $fresh,
            (int) $fresh->reserved_cost_micros,
            (int) $fresh->reserved_native_units,
            'upper_bound_estimate',
            ['cost_evidence_source' => 'manual'],
            AiOperationStatus::ManualReview,
        );
    }

    /** @param array<string, bool|int|string|null> $policy */
    public function fingerprint(array $policy, string $content): string
    {
        $canonical = json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', $canonical.'|'.hash('sha256', $content), (string) config('app.key'));
    }

    private function assertInitiator(Workspace $workspace, ?User $initiator): void
    {
        if ($initiator !== null && $workspace->membershipFor($initiator) === null) {
            throw new RuntimeException('The initiator is not a member of this workspace.');
        }
    }

    private function envelopeId(Workspace $workspace, AiBudgetEnvelope|string|null $envelope): ?string
    {
        if ($envelope === null) {
            return null;
        }
        $id = $envelope instanceof AiBudgetEnvelope ? (string) $envelope->id : $envelope;
        if (AiBudgetEnvelope::query()->where('workspace_id', $workspace->id)->whereKey($id)->doesntExist()) {
            throw new RuntimeException('The budget envelope belongs to another workspace.');
        }

        return $id;
    }

    private function runLimit(Workspace $workspace, AiTask $task): int
    {
        return $task === AiTask::ConnectionTest
            ? (int) config('ai.setup_test_limit_micros')
            : (int) AiTaskSetting::query()
                ->where('workspace_id', $workspace->id)
                ->where('task', $task->value)
                ->value('per_run_limit_micros');
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function defaultEvidenceSource(string $costBasis): string
    {
        return match ($costBasis) {
            'provider_exact' => 'response',
            'generation_lookup' => 'generation_lookup',
            'provider_logs' => 'provider_logs',
            'formula_estimate', 'upper_bound_estimate' => 'formula_estimate',
            'manual' => 'manual',
            default => 'response',
        };
    }
}
