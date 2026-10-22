<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Exceptions\SafeProviderException;
use App\Domain\Ai\Providers\AiProviderRegistry;
use App\Models\AiProviderCallAttempt;
use App\Models\AiProviderCredentialVersion;
use App\Models\AiProviderOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reconciles uncertain provider calls without ever issuing a replacement
 * provider action. Expired execution leases are fenced before reconciliation.
 */
final class ReconcileAiProviderOperations
{
    public function __construct(
        private readonly AiProviderRegistry $providers,
        private readonly AiBudgetGateway $budget,
        private readonly ProviderErrorRedactor $redactor,
    ) {}

    public function handle(?string $operationId = null): int
    {
        $now = now();
        $query = AiProviderOperation::query()
            ->where(function ($query) use ($now): void {
                $query->where(function ($query) use ($now): void {
                    $query
                        ->whereIn('status', [AiOperationStatus::Uncertain, AiOperationStatus::Reconciling])
                        ->where(function ($query) use ($now): void {
                            $query
                                ->whereNull('reconcile_after')
                                ->orWhere('reconcile_after', '<=', $now)
                                ->orWhere(fn ($query) => $query->whereNotNull('finalize_after')->where('finalize_after', '<=', $now));
                        });
                })->orWhere(function ($query) use ($now): void {
                    $query
                        ->where('status', AiOperationStatus::Executing)
                        ->where(function ($query) use ($now): void {
                            $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $now);
                        });
                });
            })
            ->where(fn ($query) => $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $now))
            ->orderBy('created_at');
        if ($operationId !== null) {
            $query->whereKey($operationId);
        }

        $handled = 0;
        $query->limit(100)->pluck('id')->each(function (string $id) use (&$handled): void {
            $operation = $this->claim($id);
            if ($operation === null) {
                return;
            }
            $this->reconcileOne($operation);
            $handled++;
        });

        return $handled;
    }

    private function claim(string $operationId): ?AiProviderOperation
    {
        return DB::transaction(function () use ($operationId): ?AiProviderOperation {
            $operation = AiProviderOperation::query()->whereKey($operationId)->lockForUpdate()->first();
            if ($operation === null) {
                return null;
            }
            $executionExpired = $operation->status === AiOperationStatus::Executing
                && ($operation->lease_expires_at === null || ! $operation->lease_expires_at->isFuture());
            $reconcileDue = $operation->reconcile_after === null || ! $operation->reconcile_after->isFuture();
            $finalizeDue = $operation->finalize_after !== null && ! $operation->finalize_after->isFuture();
            if (! in_array($operation->status, [AiOperationStatus::Uncertain, AiOperationStatus::Reconciling, AiOperationStatus::Executing], true)
                || (! $executionExpired && ! $reconcileDue && ! $finalizeDue)
                || ($operation->lease_expires_at !== null && $operation->lease_expires_at->isFuture())) {
                return null;
            }

            if ($executionExpired) {
                AiProviderCallAttempt::query()
                    ->where('operation_id', $operation->id)
                    ->where('kind', 'execute')
                    ->where('status', 'started')
                    ->orderByDesc('attempt_number')
                    ->first()?->forceFill([
                        'status' => 'ambiguous',
                        'safe_error_code' => 'execution_lease_expired',
                        'safe_metadata' => ['reason' => 'lease_expired'],
                    ])->save();
                $operation->forceFill([
                    'status' => AiOperationStatus::Uncertain,
                    'safe_error_code' => 'execution_lease_expired',
                    'safe_error_message' => 'The provider execution lease expired before a result was recorded.',
                    'reconcile_after' => now(),
                    'finalize_after' => $operation->finalize_after
                        ?? now()->addHours(max(1, (int) config('ai.finalize_unknown_after_hours', 24))),
                    // Incrementing before assigning the reconciliation fence
                    // invalidates the old execution worker immediately.
                    'fencing_token' => (int) $operation->fencing_token + 1,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                ])->save();
            }

            $operation->forceFill([
                'status' => AiOperationStatus::Reconciling,
                'lease_owner' => (string) Str::uuid(),
                'fencing_token' => (int) $operation->fencing_token + 1,
                'lease_expires_at' => now()->addMinutes(max(1, (int) config('ai.reconciliation_lease_minutes', 5))),
            ])->save();

            return $operation->fresh();
        }, 3);
    }

    private function reconcileOne(AiProviderOperation $operation): void
    {
        if ($operation->finalize_after !== null && $operation->finalize_after->isPast()) {
            $this->budget->finalizeUncertain($operation);

            return;
        }

        $version = AiProviderCredentialVersion::query()->find($operation->credential_version_id);
        if ($version === null || $version->encrypted_secret === null || $version->wiped_at !== null) {
            $this->budget->finalizeUncertain($operation);

            return;
        }

        $attemptNumber = ((int) AiProviderCallAttempt::query()->where('operation_id', $operation->id)->max('attempt_number')) + 1;
        $attempt = AiProviderCallAttempt::query()->create([
            'operation_id' => $operation->id,
            'attempt_number' => $attemptNumber,
            'kind' => 'reconcile',
            'status' => 'started',
            'fencing_token' => $operation->fencing_token,
        ]);

        try {
            $result = $this->providers->for($operation->provider)->reconcile((string) $version->encrypted_secret, $operation);
            if ($result === null) {
                $attempt->forceFill(['status' => 'completed'])->save();
                $this->budget->markUncertain(
                    $operation,
                    'uncertain_provider_charge',
                    'Reconciliation did not return authoritative charge evidence.',
                    $operation->lease_owner,
                    (int) $operation->fencing_token,
                );

                return;
            }

            $attempt->forceFill([
                'status' => 'completed',
                'provider_request_id' => $result->providerRequestId,
                'provider_generation_id' => $result->providerGenerationId,
                'safe_metadata' => $result->providerMetadata,
            ])->save();
            $this->budget->settle(
                operation: $operation,
                actualCostMicros: $result->costMicros,
                actualNativeUnits: $result->nativeUnits,
                costBasis: $result->costBasis,
                usage: [
                    'native_unit' => $result->nativeUnit,
                    'raw_provider_cost' => $result->rawProviderCost,
                    'provider_response_id' => $result->providerResponseId,
                    'provider_metadata' => $result->providerMetadata,
                    'cost_evidence_source' => $result->evidenceSource,
                    'provider_request_id' => $result->providerRequestId,
                    'provider_generation_id' => $result->providerGenerationId,
                ],
                finalStatus: AiOperationStatus::Reconciled,
                leaseOwner: $operation->lease_owner,
                fencingToken: (int) $operation->fencing_token,
            );
        } catch (SafeProviderException $exception) {
            $attempt->forceFill([
                'status' => 'ambiguous',
                'safe_error_code' => $exception->safeCode,
                'http_status' => $exception->httpStatus,
                'safe_metadata' => $exception->safeMetadata,
                'retry_after_at' => $exception->retryAfterSeconds === null ? null : now()->addSeconds($exception->retryAfterSeconds),
            ])->save();
            $this->budget->markUncertain(
                $operation,
                $exception->safeCode,
                $exception->getMessage(),
                $operation->lease_owner,
                (int) $operation->fencing_token,
                $exception->retryAfterSeconds,
                $exception->safeMetadata,
            );
        } catch (Throwable $exception) {
            $safe = $this->redactor->from($exception, true, $operation->provider);
            $attempt->forceFill([
                'status' => 'ambiguous',
                'safe_error_code' => $safe->safeCode,
                'http_status' => $safe->httpStatus,
                'safe_metadata' => $safe->safeMetadata,
                'retry_after_at' => $safe->retryAfterSeconds === null ? null : now()->addSeconds($safe->retryAfterSeconds),
            ])->save();
            $this->budget->markUncertain(
                $operation,
                $safe->safeCode,
                $safe->getMessage(),
                $operation->lease_owner,
                (int) $operation->fencing_token,
                $safe->retryAfterSeconds,
                $safe->safeMetadata,
            );
        }
    }
}
