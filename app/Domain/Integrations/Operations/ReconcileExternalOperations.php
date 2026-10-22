<?php

namespace App\Domain\Integrations\Operations;

use App\Domain\Integrations\Contracts\CapabilityRequest;
use App\Domain\Integrations\Enums\ExternalOperationAttemptKind;
use App\Domain\Integrations\Enums\ExternalOperationAttemptStatus;
use App\Domain\Integrations\Enums\IntegrationOperationStatus;
use App\Domain\Integrations\Support\ExternalErrorRedactor;
use App\Models\ExternalOperation;
use App\Models\ExternalOperationAttempt;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reconcile due neutral integration operations and close unresolved holds at
 * a bounded deadline. The scheduled request intentionally carries no source
 * payload; providers reconcile by the durable request/snapshot identity on
 * the operation row.
 */
final class ReconcileExternalOperations
{
    public function __construct(
        private readonly ExecuteExternalOperation $executor,
        private readonly IntegrationBudgetGateway $budget,
        private readonly ExternalErrorRedactor $errors,
    ) {}

    public function handle(?string $operationId = null): int
    {
        $now = now();
        $query = ExternalOperation::query()
            ->where(function ($query) use ($now): void {
                $query->where(function ($query) use ($now): void {
                    $query
                        ->whereIn('status', [
                            IntegrationOperationStatus::Awaiting,
                            IntegrationOperationStatus::Ambiguous,
                            IntegrationOperationStatus::Reconciling,
                            IntegrationOperationStatus::ReconciliationPending,
                        ])
                        ->where(function ($query) use ($now): void {
                            $query
                                ->whereNull('reconcile_after')
                                ->orWhere('reconcile_after', '<=', $now)
                                ->orWhere(fn ($query) => $query->whereNotNull('finalize_after')->where('finalize_after', '<=', $now));
                        });
                })->orWhere(function ($query) use ($now): void {
                    // A worker can die after claiming an operation and before
                    // recording a provider response. Its reservation must be
                    // sent through the normal reconciliation/finalisation
                    // path once the execution lease expires.
                    $query
                        ->where('status', IntegrationOperationStatus::Executing)
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

    private function claim(string $operationId): ?ExternalOperation
    {
        return DB::transaction(function () use ($operationId): ?ExternalOperation {
            $operation = ExternalOperation::query()->whereKey($operationId)->lockForUpdate()->first();
            if ($operation === null) {
                return null;
            }
            $executionExpired = $operation->status === IntegrationOperationStatus::Executing
                && ($operation->lease_expires_at === null || ! $operation->lease_expires_at->isFuture());
            $reconcileDue = $operation->reconcile_after === null || ! $operation->reconcile_after->isFuture();
            $finalizeDue = $operation->finalize_after !== null && ! $operation->finalize_after->isFuture();
            if (! in_array($operation->status, [
                IntegrationOperationStatus::Awaiting,
                IntegrationOperationStatus::Ambiguous,
                IntegrationOperationStatus::Reconciling,
                IntegrationOperationStatus::ReconciliationPending,
                IntegrationOperationStatus::Executing,
            ], true)
                || (! $executionExpired && ! $reconcileDue && ! $finalizeDue)
                || ($operation->lease_expires_at !== null && $operation->lease_expires_at->isFuture())) {
                return null;
            }

            if ($executionExpired) {
                // Preserve the original execute attempt as ambiguous when it
                // was still open. A completed attempt can also precede a
                // worker crash before settlement, so the operation itself is
                // the source of truth and remains recoverable either way.
                ExternalOperationAttempt::query()
                    ->where('operation_id', $operation->id)
                    ->where('kind', ExternalOperationAttemptKind::Execute)
                    ->where('status', ExternalOperationAttemptStatus::Started)
                    ->orderByDesc('attempt_number')
                    ->first()?->forceFill([
                        'status' => ExternalOperationAttemptStatus::Ambiguous,
                        'safe_error_code' => 'execution_lease_expired',
                    ])->save();
                $operation->forceFill([
                    'status' => IntegrationOperationStatus::ReconciliationPending,
                    'safe_error_code' => 'execution_lease_expired',
                    'safe_error_message' => 'The provider execution lease expired before a result was recorded.',
                    'reconcile_after' => now(),
                    'finalize_after' => $operation->finalize_after
                        ?? now()->addHours(max(1, (int) config('integrations.finalize_ambiguous_after_hours', 24))),
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                ])->save();
                $reconcileDue = true;
            }

            $operation->forceFill([
                'status' => IntegrationOperationStatus::Reconciling,
                'lease_owner' => (string) Str::uuid(),
                'lease_expires_at' => now()->addMinutes(5),
            ])->save();

            return $operation->fresh();
        }, 3);
    }

    private function reconcileOne(ExternalOperation $operation): void
    {
        if ($operation->finalize_after !== null && $operation->finalize_after->isPast()) {
            $this->budget->finalizeAmbiguous($operation);

            return;
        }

        $workspace = Workspace::query()->find($operation->workspace_id);
        if ($workspace === null) {
            // Workspace deletion normally removes this row first. If a
            // cleanup race leaves it behind, do not keep its reservation held.
            $this->budget->finalizeAmbiguous($operation);

            return;
        }

        $attempt = ExternalOperationAttempt::query()->create([
            'operation_id' => $operation->id,
            'attempt_number' => ((int) ExternalOperationAttempt::query()->where('operation_id', $operation->id)->max('attempt_number')) + 1,
            'kind' => ExternalOperationAttemptKind::Reconcile,
            'status' => ExternalOperationAttemptStatus::Started,
        ]);
        $request = new CapabilityRequest(
            capability: (string) $operation->capability,
            input: [],
            metadata: ['scheduled_reconciliation' => true],
            providerKey: (string) $operation->provider,
            sourceRunId: $operation->source_run_id,
            policyVersion: (int) ($operation->policy_version ?? 1),
        );

        try {
            $result = $this->executor->reconcile($workspace, $operation, $request);
            $attempt->forceFill([
                'status' => $result->state === 'ambiguous' || $result->error?->ambiguous === true
                    ? ExternalOperationAttemptStatus::Ambiguous
                    : ExternalOperationAttemptStatus::Reconciled,
                'provider_request_id' => $result->providerRequestId,
                'provider_snapshot_id' => $result->providerSnapshotId,
                'safe_error_code' => $result->error?->code,
            ])->save();
        } catch (Throwable $exception) {
            $error = $this->errors->fromThrowable($exception, 'reconciliation_unavailable', ambiguous: true);
            $attempt->forceFill([
                'status' => ExternalOperationAttemptStatus::Ambiguous,
                'safe_error_code' => $error->code,
            ])->save();
            $this->budget->markAmbiguous($operation, $error->code, $error->message);
        }
    }
}
