<?php

namespace App\Domain\Integrations\Operations;

use App\Domain\Integrations\Contracts\CapabilityRequest;
use App\Domain\Integrations\Contracts\CapabilityResult;
use App\Domain\Integrations\DTOs\CapabilityDefinition;
use App\Domain\Integrations\DTOs\ExternalCostEstimate;
use App\Domain\Integrations\DTOs\SafeExternalError;
use App\Domain\Integrations\Enums\ExternalOperationAttemptKind;
use App\Domain\Integrations\Enums\ExternalOperationAttemptStatus;
use App\Domain\Integrations\Enums\IntegrationConnectionStatus;
use App\Domain\Integrations\Enums\IntegrationOperationStatus;
use App\Domain\Integrations\Exceptions\CapabilityUnavailable;
use App\Domain\Integrations\Providers\ProviderRegistry;
use App\Domain\Integrations\Support\CapabilityCatalogue;
use App\Domain\Integrations\Support\CapabilitySchemaValidator;
use App\Domain\Integrations\Support\ExternalErrorRedactor;
use App\Models\ExternalOperation;
use App\Models\ExternalOperationAttempt;
use App\Models\IntegrationCapabilityRoute;
use App\Models\IntegrationConnection;
use App\Models\IntegrationCredentialVersion;
use App\Models\IntegrationProviderRegistration;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ExecuteExternalOperation
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly CapabilityCatalogue $catalogue,
        private readonly CapabilitySchemaValidator $schemas,
        private readonly IntegrationBudgetGateway $budget,
        private readonly IntegrationCostEstimator $costs,
        private readonly ExternalErrorRedactor $errors,
    ) {}

    /**
     * Supports both (workspace, request, key, actor, sourceRunId) and
     * (workspace, actor, request, key, sourceRunId) call shapes while the
     * HTTP/MCP adapters converge on one request envelope.
     */
    public function execute(
        Workspace $workspace,
        CapabilityRequest|User $first,
        CapabilityRequest|string $second,
        string|User|null $third = null,
        ?string $fourth = null,
    ): CapabilityResult {
        [$request, $idempotencyKey, $actor, $sourceRunId] = $this->arguments($first, $second, $third, $fourth);
        $definition = $this->catalogue->get($request->capability);
        if (! $definition->enabled) {
            throw new CapabilityUnavailable($definition->name, $definition->availabilityReason ?? 'disabled');
        }
        $input = $this->schemas->validateRequest($definition, $request->input);
        $request = new CapabilityRequest(
            capability: $request->capability,
            input: $input,
            metadata: $request->metadata,
            providerKey: $request->providerKey,
            sourceRunId: $request->sourceRunId ?? $sourceRunId,
            policyVersion: $request->policyVersion,
            policySnapshot: $request->policySnapshot,
        );
        $this->schemas->assertMetadataSize($request->metadata);
        $this->schemas->assertRequestEnvelopeSize($request->toArray());
        $fingerprint = $this->schemas->fingerprint(
            ['input' => $request->input, 'metadata' => $request->metadata, 'policy' => $request->policySnapshot],
            $request->capability,
            $definition->schemaVersion,
        );
        $registration = $this->resolveRegistration($workspace, $request, $definition);
        $estimate = $this->costs->estimate($registration, $definition, $request);
        $operation = $this->budget->reserve(
            workspace: $workspace,
            initiator: $actor,
            provider: (string) $registration->provider,
            capability: (string) $registration->capability,
            operation: $this->operationName($request),
            endpoint: (string) $registration->capability,
            estimate: $estimate,
            idempotencyKey: $idempotencyKey,
            requestFingerprint: $fingerprint,
            sourceRunId: $request->sourceRunId,
            resolvedRegistration: $registration,
        );

        if ($operation->status->terminal()) {
            return $this->resultForExisting($operation);
        }
        if ($operation->status !== IntegrationOperationStatus::Reserved) {
            return new CapabilityResult($this->stateForOperation($operation), metadata: ['operation_id' => $operation->id]);
        }

        [$operation, $attempt] = $this->claim($operation, $actor);
        if ($attempt === null) {
            return new CapabilityResult(
                state: 'failed',
                error: new SafeExternalError(
                    'pre_call_blocked',
                    'The connection was disabled before provider activity.',
                ),
                metadata: ['operation_id' => $operation->id],
            );
        }
        $started = hrtime(true);
        try {
            // Resolve the adapter only after the durable operation has been
            // claimed and its registration/connection/credential bindings
            // have been checked. This prevents a stale route resolution from
            // selecting a different provider at execution time.
            $adapter = $this->providers->for((string) $operation->provider);
            $result = $adapter->execute($request);
        } catch (Throwable $exception) {
            $error = $this->errors->fromThrowable($exception, 'provider_unavailable', ambiguous: true);
            $attempt->forceFill([
                'status' => ExternalOperationAttemptStatus::Ambiguous,
                'safe_error_code' => $error->code,
                'latency_ms' => $this->latency($started),
            ])->save();
            $this->budget->markAmbiguous($operation, $error->code, $error->message);

            return new CapabilityResult(
                state: 'ambiguous',
                error: $error,
                metadata: ['operation_id' => $operation->id],
            );
        }

        $validated = null;
        try {
            $this->schemas->assertMetadataSize($result->metadata);
            if (! in_array($result->state, ['awaiting', 'ambiguous', 'failed'], true)
                && $result->error?->ambiguous !== true) {
                $validated = $this->schemas->validateResult($definition, $result->data);
            }
            $this->schemas->assertResultEnvelopeSize([
                ...$result->toArray(),
                'data' => $validated ?? $result->data,
            ]);
            $safeMetadata = $this->safeMetadata($result);
        } catch (Throwable $exception) {
            $error = $this->errors->fromThrowable($exception, 'output_invalid', ambiguous: true);
            $attempt->forceFill([
                'status' => ExternalOperationAttemptStatus::Ambiguous,
                'safe_error_code' => $error->code,
                'latency_ms' => $this->latency($started),
            ])->save();
            $this->budget->markAmbiguous($operation, $error->code, $error->message);

            return new CapabilityResult('ambiguous', error: $error, metadata: ['operation_id' => $operation->id]);
        }

        $attempt->forceFill([
            'status' => $result->error?->ambiguous ? ExternalOperationAttemptStatus::Ambiguous : ExternalOperationAttemptStatus::Completed,
            'provider_request_id' => $result->providerRequestId,
            'provider_snapshot_id' => $result->providerSnapshotId,
            'safe_error_code' => $result->error?->code,
            'latency_ms' => $this->latency($started),
        ])->save();
        $operation->forceFill([
            'provider_request_id' => $result->providerRequestId,
            'provider_snapshot_id' => $result->providerSnapshotId,
            'safe_metadata' => $safeMetadata,
        ])->save();

        if ($result->state === 'awaiting') {
            $this->budget->markAwaiting($operation, $result->providerRequestId, $result->providerSnapshotId);

            return $result;
        }
        if ($result->state === 'ambiguous' || $result->error?->ambiguous === true) {
            $error = $result->error ?? new SafeExternalError(
                'provider_ambiguous',
                'The provider response could not be confirmed.',
                true,
            );
            $this->budget->markAmbiguous($operation, $error->code, $error->message);

            return $result;
        }
        if ($result->state === 'failed') {
            $error = $result->error ?? new SafeExternalError(
                'provider_rejected',
                'The provider rejected the bounded request.',
            );
            $this->budget->releaseDefinitelyNotSent($operation, $error->code, $error->message);

            return $result;
        }

        $actual = $result->cost ?? $estimate;
        $this->budget->settle(
            operation: $operation,
            actual: $actual,
            usage: [
                'provider_request_id' => $result->providerRequestId,
                'provider_snapshot_id' => $result->providerSnapshotId,
                'safe_metadata' => $safeMetadata,
            ],
            status: 'succeeded',
        );

        return new CapabilityResult(
            state: $result->state,
            data: $validated ?? [],
            cost: $actual,
            providerRequestId: $result->providerRequestId,
            providerSnapshotId: $result->providerSnapshotId,
            metadata: [...$result->metadata, 'operation_id' => $operation->id],
        );
    }

    public function run(
        Workspace $workspace,
        CapabilityRequest|User $first,
        CapabilityRequest|string $second,
        string|User|null $third = null,
        ?string $fourth = null,
    ): CapabilityResult {
        return $this->execute($workspace, $first, $second, $third, $fourth);
    }

    public function reconcile(
        Workspace $workspace,
        ExternalOperation $operation,
        CapabilityRequest $request,
    ): CapabilityResult {
        if ((int) $operation->workspace_id !== (int) $workspace->id) {
            throw new RuntimeException('The operation belongs to another workspace.');
        }
        if (! in_array($operation->status, [
            IntegrationOperationStatus::Awaiting,
            IntegrationOperationStatus::Ambiguous,
            IntegrationOperationStatus::Reconciling,
            IntegrationOperationStatus::ReconciliationPending,
        ], true)) {
            return $this->resultForExisting($operation);
        }
        $this->assertReconciliationRequest($operation, $request);
        $definition = $this->catalogue->get($request->capability);
        $registration = IntegrationProviderRegistration::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($operation->registration_id)
            ->firstOrFail();
        $this->assertOperationBindings($operation, $registration);
        $adapter = $this->providers->for((string) $registration->provider);
        $locked = $operation->fresh();
        $this->schemas->assertMetadataSize($request->metadata);
        $this->schemas->assertRequestEnvelopeSize($request->toArray());
        try {
            $result = $adapter->reconcile($request, $locked);
        } catch (Throwable $exception) {
            $error = $this->errors->fromThrowable($exception, 'reconciliation_unavailable', ambiguous: true);
            $this->budget->markAmbiguous($locked, $error->code, $error->message);

            return new CapabilityResult('ambiguous', error: $error, metadata: ['operation_id' => $operation->id]);
        }
        if ($result === null) {
            $this->budget->markAmbiguous($locked, 'reconciliation_incomplete', 'The provider did not confirm the operation.');

            return new CapabilityResult('ambiguous', metadata: ['operation_id' => $operation->id]);
        }
        $validated = null;
        try {
            $this->schemas->assertMetadataSize($result->metadata);
            if (! in_array($result->state, ['awaiting', 'ambiguous', 'failed'], true)
                && $result->error?->ambiguous !== true) {
                $validated = $this->schemas->validateResult($definition, $result->data);
            }
            $this->schemas->assertResultEnvelopeSize([
                ...$result->toArray(),
                'data' => $validated ?? $result->data,
            ]);
            $safeMetadata = $this->safeMetadata($result);
        } catch (Throwable $exception) {
            $error = $this->errors->fromThrowable($exception, 'output_invalid', ambiguous: true);
            $this->budget->markAmbiguous($locked, $error->code, $error->message);

            return new CapabilityResult('ambiguous', error: $error, metadata: ['operation_id' => $operation->id]);
        }
        if ($result->state === 'awaiting' || $result->state === 'ambiguous' || $result->error?->ambiguous === true) {
            $providerError = $result->error;
            $errorCode = $providerError === null ? 'reconciliation_incomplete' : $providerError->code;
            $errorMessage = $providerError === null
                ? 'The provider did not confirm the operation.'
                : $providerError->message;
            $this->budget->markAmbiguous(
                $locked,
                $errorCode,
                $errorMessage,
            );

            return $result;
        }
        if ($result->state === 'failed') {
            $providerError = $result->error;
            $errorCode = $providerError === null ? 'provider_rejected' : $providerError->code;
            $errorMessage = $providerError === null
                ? 'The provider confirmed that the request was not accepted.'
                : $providerError->message;
            $this->budget->releaseDefinitelyNotSent(
                $locked,
                $errorCode,
                $errorMessage,
            );

            return $result;
        }
        $actual = $result->cost ?? new ExternalCostEstimate(
            (int) $locked->reserved_native_units,
            (string) ($locked->native_unit ?? 'requests'),
            (int) $locked->reserved_cost_micros,
            'reconciled_upper_bound',
        );
        $this->budget->settle($locked, $actual, [
            'provider_request_id' => $result->providerRequestId,
            'provider_snapshot_id' => $result->providerSnapshotId,
            'safe_metadata' => $safeMetadata,
        ], 'reconciled');

        return new CapabilityResult(
            $result->state,
            $validated ?? [],
            $actual,
            $result->providerRequestId,
            $result->providerSnapshotId,
            metadata: [...$result->metadata, 'operation_id' => $operation->id],
        );
    }

    /**
     * Reconciliation may carry bounded control metadata, but it must never
     * accept a replacement action payload. The original request fingerprint
     * intentionally cannot match a poll request; its immutable operation
     * references provide the safe boundary instead.
     */
    private function assertReconciliationRequest(ExternalOperation $operation, CapabilityRequest $request): void
    {
        if ($request->capability !== (string) $operation->capability
            || ($request->providerKey !== null && $request->providerKey !== (string) $operation->provider)
            || ($request->sourceRunId !== null && $request->sourceRunId !== $operation->source_run_id)
            || $request->policyVersion !== (int) ($operation->policy_version ?? 1)
            || $request->input !== []
            || $request->policySnapshot !== []) {
            throw new RuntimeException('The reconciliation request does not match the reserved operation.');
        }
    }

    /**
     * @return array{0: CapabilityRequest, 1: string, 2: User|null, 3: string|null}
     */
    private function arguments(
        CapabilityRequest|User $first,
        CapabilityRequest|string $second,
        string|User|null $third,
        ?string $fourth,
    ): array {
        if ($first instanceof CapabilityRequest) {
            if (! is_string($second)) {
                throw new RuntimeException('An idempotency key is required.');
            }

            return [$first, $second, $third instanceof User ? $third : null, $fourth];
        }
        if (! $second instanceof CapabilityRequest || ! is_string($third)) {
            throw new RuntimeException('The external operation arguments are invalid.');
        }

        return [$second, $third, $first, $fourth];
    }

    private function resolveRegistration(
        Workspace $workspace,
        CapabilityRequest $request,
        CapabilityDefinition $definition,
    ): IntegrationProviderRegistration {
        $query = IntegrationProviderRegistration::query()
            ->where('workspace_id', $workspace->id)
            ->where('capability', $definition->name)
            ->where('enabled', true);
        if ($request->providerKey !== null) {
            $query->where('provider', $request->providerKey);
        } else {
            $route = IntegrationCapabilityRoute::query()
                ->where('workspace_id', $workspace->id)
                ->where('capability', $definition->name)
                ->where('status', 'active')
                ->with('activeRegistration')
                ->first();
            if ($route?->activeRegistration === null) {
                throw new CapabilityUnavailable($definition->name, 'missing_route');
            }
            $query->whereKey($route->activeRegistration->id);
        }
        $registration = $query->first();
        if ($registration === null) {
            throw new CapabilityUnavailable($definition->name, 'missing_registration');
        }
        if ((int) $registration->capability_schema_version !== $definition->schemaVersion) {
            throw new CapabilityUnavailable($definition->name, 'schema_version_mismatch');
        }
        if ((string) $registration->code_definition_hash !== $this->catalogue->definitionHash($definition)) {
            throw new CapabilityUnavailable($definition->name, 'definition_hash_drift');
        }
        if (! $this->providers->supports((string) $registration->provider, $definition->name)) {
            throw new CapabilityUnavailable($definition->name, 'provider_capability_missing');
        }

        return $registration;
    }

    private function assertOperationBindings(
        ExternalOperation $operation,
        IntegrationProviderRegistration $registration,
    ): void {
        if ((int) $registration->connection_id !== (int) $operation->connection_id
            || (string) $registration->provider !== (string) $operation->provider
            || (string) $registration->capability !== (string) $operation->capability) {
            throw new RuntimeException('The external operation provider registration binding is invalid.');
        }

        $connection = IntegrationConnection::query()
            ->where('workspace_id', $operation->workspace_id)
            ->whereKey($operation->connection_id)
            ->firstOrFail();
        $credential = IntegrationCredentialVersion::query()
            ->where('workspace_id', $operation->workspace_id)
            ->where('connection_id', $connection->id)
            ->whereKey($operation->credential_version_id)
            ->first();
        if ((string) $connection->provider !== (string) $registration->provider
            || $credential === null
            || $credential->wiped_at !== null
            || ! is_string($credential->encrypted_secret)
            || $credential->encrypted_secret === '') {
            throw new RuntimeException('The external operation connection or credential binding is invalid.');
        }
    }

    /** @return array{0: ExternalOperation, 1: ExternalOperationAttempt|null} */
    private function claim(ExternalOperation $operation, ?User $actor): array
    {
        return DB::transaction(function () use ($operation, $actor): array {
            $locked = ExternalOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== IntegrationOperationStatus::Reserved) {
                throw new RuntimeException('The external operation has already been claimed.');
            }
            $registration = IntegrationProviderRegistration::query()
                ->where('workspace_id', $locked->workspace_id)
                ->whereKey($locked->registration_id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $registration->enabled
                || (string) $registration->provider !== (string) $locked->provider
                || (string) $registration->capability !== (string) $locked->capability) {
                $released = $this->budget->releaseDefinitelyNotSentWithinTransaction(
                    $locked,
                    'pre_call_blocked',
                    'The provider registration changed before provider activity.',
                );

                return [$released, null];
            }
            $connection = IntegrationConnection::query()
                ->where('workspace_id', $locked->workspace_id)
                ->whereKey($locked->connection_id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $registration->connection_id !== (int) $connection->id
                || (string) $connection->provider !== (string) $registration->provider) {
                $released = $this->budget->releaseDefinitelyNotSentWithinTransaction(
                    $locked,
                    'pre_call_blocked',
                    'The provider connection changed before provider activity.',
                );

                return [$released, null];
            }
            $credential = IntegrationCredentialVersion::query()
                ->where('workspace_id', $locked->workspace_id)
                ->where('connection_id', $connection->id)
                ->whereKey($locked->credential_version_id)
                ->lockForUpdate()
                ->first();
            if ($connection->status !== IntegrationConnectionStatus::Healthy
                || $credential === null
                || ! $credential->isUsable()
                || (int) $connection->active_credential_version_id !== (int) $credential->id) {
                $released = $this->budget->releaseDefinitelyNotSentWithinTransaction(
                    $locked,
                    'pre_call_blocked',
                    'The connection or credential changed before provider activity.',
                );

                return [$released, null];
            }
            $locked->forceFill([
                'status' => IntegrationOperationStatus::Executing,
                'started_at' => now(),
                'lease_owner' => (string) Str::uuid(),
                'lease_expires_at' => now()->addMinutes(10),
                'initiated_by_user_id' => $actor !== null ? $actor->id : $locked->initiated_by_user_id,
            ])->save();
            $attempt = ExternalOperationAttempt::query()->create([
                'operation_id' => $locked->id,
                'attempt_number' => ((int) ExternalOperationAttempt::query()->where('operation_id', $locked->id)->max('attempt_number')) + 1,
                'kind' => ExternalOperationAttemptKind::Execute,
                'status' => ExternalOperationAttemptStatus::Started,
            ]);

            return [$locked->fresh(), $attempt];
        }, 3);
    }

    private function operationName(CapabilityRequest $request): string
    {
        return str_replace('.', '_', $request->capability);
    }

    private function latency(int $started): int
    {
        return max(0, (int) ((hrtime(true) - $started) / 1_000_000));
    }

    /** @return array<string, mixed> */
    private function safeMetadata(CapabilityResult $result): array
    {
        $metadata = [
            'state' => $result->state,
            'provider_request_id' => $result->providerRequestId,
            'provider_snapshot_id' => $result->providerSnapshotId,
        ];
        $this->schemas->assertMetadataSize($metadata);

        return $metadata;
    }

    private function stateForOperation(ExternalOperation $operation): string
    {
        return match ($operation->status) {
            IntegrationOperationStatus::Awaiting => 'awaiting',
            IntegrationOperationStatus::Ambiguous,
            IntegrationOperationStatus::Uncertain,
            IntegrationOperationStatus::Reconciling,
            IntegrationOperationStatus::ReconciliationPending => 'ambiguous',
            IntegrationOperationStatus::Failed => 'failed',
            default => 'awaiting',
        };
    }

    private function resultForExisting(ExternalOperation $operation): CapabilityResult
    {
        return new CapabilityResult(
            state: match ($operation->status) {
                IntegrationOperationStatus::Succeeded,
                IntegrationOperationStatus::Reconciled => 'complete',
                IntegrationOperationStatus::Failed,
                IntegrationOperationStatus::Cancelled => 'failed',
                IntegrationOperationStatus::Ambiguous,
                IntegrationOperationStatus::Uncertain,
                IntegrationOperationStatus::Reconciling,
                IntegrationOperationStatus::ReconciliationPending => 'ambiguous',
                default => 'awaiting',
            },
            metadata: ['operation_id' => $operation->id, 'idempotent_replay' => true],
        );
    }
}
