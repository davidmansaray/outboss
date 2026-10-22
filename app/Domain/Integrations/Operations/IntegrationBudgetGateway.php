<?php

namespace App\Domain\Integrations\Operations;

use App\Domain\Integrations\Contracts\ExternalBudgetGateway as ExternalBudgetGatewayContract;
use App\Domain\Integrations\DTOs\ExternalCostEstimate;
use App\Domain\Integrations\Enums\IntegrationConnectionStatus;
use App\Domain\Integrations\Enums\IntegrationCredentialStatus;
use App\Domain\Integrations\Enums\IntegrationOperationStatus;
use App\Domain\Integrations\Exceptions\ExternalBudgetExceeded;
use App\Domain\Integrations\Exceptions\ExternalIdempotencyConflict;
use App\Models\ExternalOperation;
use App\Models\ExternalUsageEntry;
use App\Models\IntegrationBudgetPeriod;
use App\Models\IntegrationBudgetSetting;
use App\Models\IntegrationConnection;
use App\Models\IntegrationCredentialVersion;
use App\Models\IntegrationProviderRegistration;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class IntegrationBudgetGateway implements ExternalBudgetGatewayContract
{
    public function ensureDefaults(Workspace $workspace): IntegrationBudgetSetting
    {
        return IntegrationBudgetSetting::query()->firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'currency' => (string) config('integrations.default_currency', 'USD'),
                'monthly_limit_micros' => (int) config('integrations.default_monthly_limit_micros', 50_000_000),
                'version' => 1,
            ],
        );
    }

    public function mayAdmit(Workspace $workspace, int $costMicros): bool
    {
        if ($costMicros < 0) {
            return false;
        }
        $settings = IntegrationBudgetSetting::query()->whereKey($workspace->id)->first();
        if ($settings === null || (int) $settings->monthly_limit_micros <= 0) {
            return false;
        }
        $period = $this->currentPeriod($workspace, false);
        if ($period === null) {
            return $costMicros <= (int) $settings->monthly_limit_micros;
        }

        return ! $period->budget_breach
            && ! $period->over_limit
            && (int) $period->committed_micros + (int) $period->held_micros + $costMicros
                <= (int) $period->limit_snapshot_micros;
    }

    public function reserve(
        Workspace $workspace,
        ?User $initiator,
        string $provider,
        string $capability,
        string $operation,
        string $endpoint,
        ExternalCostEstimate $estimate,
        string $idempotencyKey,
        string $requestFingerprint,
        ?string $sourceRunId = null,
        ?IntegrationProviderRegistration $resolvedRegistration = null,
    ): ExternalOperation {
        $this->assertInitiator($workspace, $initiator);
        $this->assertKey($idempotencyKey, $requestFingerprint);

        return DB::transaction(function () use (
            $workspace,
            $initiator,
            $provider,
            $capability,
            $operation,
            $endpoint,
            $estimate,
            $idempotencyKey,
            $requestFingerprint,
            $sourceRunId,
            $resolvedRegistration,
        ): ExternalOperation {
            $existing = ExternalOperation::query()
                ->where('workspace_id', $workspace->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_fingerprint, $requestFingerprint)) {
                    throw new ExternalIdempotencyConflict('This idempotency key was used for a different external request.');
                }

                return $existing;
            }

            $registrationQuery = IntegrationProviderRegistration::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate();
            if ($resolvedRegistration !== null) {
                $registrationQuery->whereKey($resolvedRegistration->id);
            } else {
                $registrationQuery
                    ->where('provider', $provider)
                    ->where('capability', $capability)
                    ->where('enabled', true);
            }
            $lockedRegistration = $registrationQuery->first();
            if ($lockedRegistration === null
                || ! $lockedRegistration->enabled
                || (string) $lockedRegistration->provider !== $provider
                || (string) $lockedRegistration->capability !== $capability) {
                throw new RuntimeException('No active integration registration exists for this capability.');
            }
            $connection = IntegrationConnection::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($lockedRegistration->connection_id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((string) $connection->provider !== (string) $lockedRegistration->provider) {
                throw new RuntimeException('The integration registration does not match its connection.');
            }
            if ($connection->status !== IntegrationConnectionStatus::Healthy) {
                throw new RuntimeException('The integration connection is not healthy.');
            }
            $version = IntegrationCredentialVersion::query()
                ->where('workspace_id', $workspace->id)
                ->where('connection_id', $connection->id)
                ->whereKey($connection->active_credential_version_id)
                ->lockForUpdate()
                ->first();
            if ($version === null
                || $version->status !== IntegrationCredentialStatus::Active
                || $version->wiped_at !== null
                || ! is_string($version->encrypted_secret)
                || $version->encrypted_secret === '') {
                throw new RuntimeException('The active integration credential is unavailable.');
            }

            $settings = IntegrationBudgetSetting::query()->whereKey($workspace->id)->lockForUpdate()->first();
            if ($settings === null) {
                $settings = IntegrationBudgetSetting::query()->create([
                    'workspace_id' => $workspace->id,
                    'currency' => (string) config('integrations.default_currency', 'USD'),
                    'monthly_limit_micros' => (int) config('integrations.default_monthly_limit_micros', 50_000_000),
                    'version' => 1,
                ]);
            }
            $start = Date::now('UTC')->startOfMonth()->toDateString();
            $end = Date::now('UTC')->addMonthNoOverflow()->startOfMonth()->toDateString();
            DB::table('integration_budget_periods')->insertOrIgnore([
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
            $period = IntegrationBudgetPeriod::query()
                ->where('workspace_id', $workspace->id)
                ->whereDate('period_start_utc', $start)
                ->lockForUpdate()
                ->firstOrFail();
            if ($period->budget_breach
                || $period->over_limit
                || (int) $period->committed_micros + (int) $period->held_micros + $estimate->costMicros
                    > (int) $period->limit_snapshot_micros) {
                throw new ExternalBudgetExceeded('The workspace integration budget cannot admit this operation.');
            }

            $period->increment('held_micros', $estimate->costMicros);

            return ExternalOperation::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'budget_period_id' => $period->id,
                'source_run_id' => $sourceRunId,
                'registration_id' => $lockedRegistration->id,
                'connection_id' => $connection->id,
                'credential_version_id' => $version->id,
                'provider' => $lockedRegistration->provider,
                'capability' => $lockedRegistration->capability,
                'operation' => $operation,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $requestFingerprint,
                'capability_schema_version' => $lockedRegistration->capability_schema_version,
                'pricing_version' => $lockedRegistration->pricing_version,
                'policy_version' => $lockedRegistration->policy_version,
                'endpoint' => $endpoint,
                'status' => IntegrationOperationStatus::Reserved,
                'native_unit' => $estimate->nativeUnit,
                'reserved_native_units' => $estimate->nativeUnits,
                'reserved_cost_micros' => $estimate->costMicros,
                'reserved_at' => now(),
                'initiated_by_user_id' => $initiator?->id,
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $usage */
    public function settle(
        ExternalOperation $operation,
        ExternalCostEstimate $actual,
        array $usage = [],
        string $status = 'succeeded',
    ): ExternalOperation {
        if ($actual->costMicros < 0 || $actual->nativeUnits < 0) {
            throw new RuntimeException('External settlement cannot be negative.');
        }
        $finalStatus = IntegrationOperationStatus::tryFrom($status);
        if ($finalStatus === null || ! $finalStatus->terminal()) {
            throw new RuntimeException('External settlement requires a terminal operation state.');
        }

        return DB::transaction(function () use ($operation, $actual, $usage, $finalStatus): ExternalOperation {
            $locked = ExternalOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->terminal()) {
                return $locked->fresh();
            }
            $period = $locked->budget_period_id === null
                ? null
                : IntegrationBudgetPeriod::query()->whereKey($locked->budget_period_id)->lockForUpdate()->firstOrFail();
            if ($period !== null) {
                $reserved = (int) $locked->reserved_cost_micros;
                $committed = (int) $period->committed_micros + $actual->costMicros;
                $period->forceFill([
                    'held_micros' => max(0, (int) $period->held_micros - $reserved),
                    'committed_micros' => $committed,
                    'over_limit' => $committed > (int) $period->limit_snapshot_micros,
                    'budget_breach' => $period->budget_breach || $actual->costMicros > $reserved,
                ])->save();
            }

            $locked->forceFill([
                'status' => $finalStatus,
                'actual_native_units' => $actual->nativeUnits,
                'actual_cost_micros' => $actual->costMicros,
                'cost_basis' => $actual->costBasis,
                'native_unit' => $actual->nativeUnit,
                'provider_request_id' => $usage['provider_request_id'] ?? $locked->provider_request_id,
                'provider_snapshot_id' => $usage['provider_snapshot_id'] ?? $locked->provider_snapshot_id,
                'safe_metadata' => $usage['safe_metadata'] ?? $locked->safe_metadata,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ])->save();

            ExternalUsageEntry::query()->create([
                'workspace_id' => $locked->workspace_id,
                'operation_id' => $locked->id,
                'budget_period_id' => $locked->budget_period_id,
                'registration_id' => $locked->registration_id,
                'provider' => $locked->provider,
                'capability' => $locked->capability,
                'operation' => $locked->operation,
                'endpoint' => $locked->endpoint,
                'entry_type' => 'charge',
                'native_unit' => $actual->nativeUnit,
                'native_units' => $actual->nativeUnits,
                'raw_provider_cost' => $usage['raw_provider_cost'] ?? null,
                'cost_micros' => $actual->costMicros,
                'cost_basis' => $actual->costBasis,
                'provider_request_id' => $usage['provider_request_id'] ?? $locked->provider_request_id,
                'provider_snapshot_id' => $usage['provider_snapshot_id'] ?? $locked->provider_snapshot_id,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return $locked->fresh();
        }, 3);
    }

    public function releaseDefinitelyNotSent(
        ExternalOperation $operation,
        string $safeCode,
        string $safeMessage,
    ): ExternalOperation {
        return DB::transaction(function () use ($operation, $safeCode, $safeMessage): ExternalOperation {
            $locked = ExternalOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();

            return $this->releaseDefinitelyNotSentWithinTransaction($locked, $safeCode, $safeMessage);
        }, 3);
    }

    /**
     * Release an operation while the caller owns the surrounding transaction.
     * The operation row must already be locked by the caller. This variant is
     * used when admission and the final pre-call connection check share one
     * transaction, so a nested transaction cannot hide the release on error.
     */
    public function releaseDefinitelyNotSentWithinTransaction(
        ExternalOperation $locked,
        string $safeCode,
        string $safeMessage,
    ): ExternalOperation {
        if ($locked->status->terminal()) {
            return $locked->fresh();
        }
        $period = $locked->budget_period_id === null
            ? null
            : IntegrationBudgetPeriod::query()->whereKey($locked->budget_period_id)->lockForUpdate()->firstOrFail();
        if ($period !== null) {
            $period->decrement('held_micros', min((int) $period->held_micros, (int) $locked->reserved_cost_micros));
        }
        $locked->forceFill([
            'status' => IntegrationOperationStatus::Failed,
            'actual_cost_micros' => 0,
            'actual_native_units' => 0,
            'cost_basis' => 'not_charged',
            'safe_error_code' => $safeCode,
            'safe_error_message' => mb_substr($safeMessage, 0, 500),
            'lease_owner' => null,
            'lease_expires_at' => null,
            'finished_at' => now(),
        ])->save();

        return $locked->fresh();
    }

    public function markAmbiguous(
        ExternalOperation $operation,
        string $safeCode,
        string $safeMessage,
    ): ExternalOperation {
        return DB::transaction(function () use ($operation, $safeCode, $safeMessage): ExternalOperation {
            $locked = ExternalOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->terminal()) {
                return $locked->fresh();
            }
            $locked->forceFill([
                'status' => IntegrationOperationStatus::Ambiguous,
                'safe_error_code' => $safeCode,
                'safe_error_message' => mb_substr($safeMessage, 0, 500),
                'reconcile_after' => now()->addMinutes(max(1, (int) config('integrations.reconcile_after_minutes', 5))),
                'finalize_after' => $locked->finalize_after
                    ?? now()->addHours(max(1, (int) config('integrations.finalize_ambiguous_after_hours', 24))),
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ])->save();

            return $locked->fresh();
        }, 3);
    }

    public function markAwaiting(
        ExternalOperation $operation,
        ?string $providerRequestId = null,
        ?string $providerSnapshotId = null,
    ): ExternalOperation {
        return DB::transaction(function () use ($operation, $providerRequestId, $providerSnapshotId): ExternalOperation {
            $locked = ExternalOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->terminal()) {
                return $locked->fresh();
            }
            $locked->forceFill([
                'status' => IntegrationOperationStatus::Awaiting,
                'provider_request_id' => $providerRequestId ?? $locked->provider_request_id,
                'provider_snapshot_id' => $providerSnapshotId ?? $locked->provider_snapshot_id,
                'reconcile_after' => now()->addMinutes(max(1, (int) config('integrations.reconcile_after_minutes', 5))),
                'finalize_after' => $locked->finalize_after
                    ?? now()->addHours(max(1, (int) config('integrations.finalize_ambiguous_after_hours', 24))),
                'lease_owner' => null,
                'lease_expires_at' => null,
            ])->save();

            return $locked->fresh();
        }, 3);
    }

    /**
     * Conservatively close an unresolved provider operation at its deadline.
     * The reservation is committed at its upper bound and moved to manual
     * review, which releases held capacity without claiming an exact charge.
     */
    public function finalizeAmbiguous(ExternalOperation $operation): ExternalOperation
    {
        $fresh = $operation->fresh();
        if (! in_array($fresh->status, [
            IntegrationOperationStatus::Awaiting,
            IntegrationOperationStatus::Ambiguous,
            IntegrationOperationStatus::Reconciling,
            IntegrationOperationStatus::ReconciliationPending,
        ], true)) {
            return $fresh;
        }

        return $this->settle(
            $fresh,
            new ExternalCostEstimate(
                (int) $fresh->reserved_native_units,
                (string) ($fresh->native_unit ?? 'requests'),
                (int) $fresh->reserved_cost_micros,
                'upper_bound_estimate',
                true,
            ),
            [],
            IntegrationOperationStatus::ManualReview->value,
        );
    }

    /** @param array<string, mixed> $payload */
    public function fingerprint(array $payload, string $capability = ''): string
    {
        $canonical = $this->canonicalize(['capability' => $capability, 'payload' => $payload]);
        $encoded = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', $encoded, (string) config('app.key'));
    }

    private function currentPeriod(Workspace $workspace, bool $create): ?IntegrationBudgetPeriod
    {
        $start = Date::now('UTC')->startOfMonth()->toDateString();
        $period = IntegrationBudgetPeriod::query()
            ->where('workspace_id', $workspace->id)
            ->whereDate('period_start_utc', $start)
            ->first();
        if ($period !== null || ! $create) {
            return $period;
        }
        $settings = $this->ensureDefaults($workspace);

        return IntegrationBudgetPeriod::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'period_start_utc' => $start],
            [
                'period_end_utc' => Date::now('UTC')->addMonthNoOverflow()->startOfMonth()->toDateString(),
                'limit_snapshot_micros' => $settings->monthly_limit_micros,
                'settings_version' => $settings->version,
            ],
        );
    }

    private function assertInitiator(Workspace $workspace, ?User $initiator): void
    {
        if ($initiator !== null && $workspace->membershipFor($initiator) === null) {
            throw new RuntimeException('The initiator is not a member of this workspace.');
        }
    }

    private function assertKey(string $idempotencyKey, string $requestFingerprint): void
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 160) {
            throw new RuntimeException('The external idempotency key is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $requestFingerprint) !== 1) {
            throw new RuntimeException('The external request fingerprint is invalid.');
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
