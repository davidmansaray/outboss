<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\DTOs\ProviderTestResult;
use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Exceptions\SafeProviderException;
use App\Domain\Ai\Providers\AiProviderRegistry;
use App\Models\AiBudgetSetting;
use App\Models\AiProviderCallAttempt;
use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialVersion;
use App\Models\AiProviderOperation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AiCredentialManager
{
    public function __construct(
        private readonly AiProviderRegistry $providers,
        private readonly AiBudgetGateway $budget,
        private readonly AiWorkspaceSettings $settings,
        private readonly AiEventRecorder $events,
    ) {}

    public function connect(Workspace $workspace, User $actor, AiProviderName $provider, string $secret): AiProviderCredential
    {
        $this->settings->ensureDefaults($workspace);

        [$credential, $version] = DB::transaction(function () use ($workspace, $actor, $provider, $secret): array {
            $credential = AiProviderCredential::query()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'capability' => $provider->capability()],
                [
                    'provider' => $provider,
                    'status' => AiCredentialStatus::Misconfigured,
                    'created_by_user_id' => $actor->id,
                    'updated_by_user_id' => $actor->id,
                ],
            );

            if ($credential->provider !== $provider && $credential->status !== AiCredentialStatus::Removed) {
                throw new RuntimeException('That workspace capability already has a provider connection.');
            }

            $nextVersion = ((int) $credential->versions()->max('version')) + 1;
            $version = $credential->versions()->create([
                'version' => $nextVersion,
                'encrypted_secret' => $secret,
                'status' => 'pending',
                'created_by_user_id' => $actor->id,
            ]);
            $credential->forceFill(['provider' => $provider, 'updated_by_user_id' => $actor->id])->save();

            return [$credential, $version];
        }, 3);

        return $this->validateAndPromote($workspace, $actor, $credential, $version, $credential->active_version_id === null ? 'added' : 'rotated');
    }

    public function rotate(Workspace $workspace, User $actor, AiProviderCredential $credential, string $secret): AiProviderCredential
    {
        return $this->connect($workspace, $actor, $credential->provider, $secret);
    }

    public function test(Workspace $workspace, User $actor, AiProviderCredential $credential): AiProviderCredential
    {
        $version = $credential->activeVersion()->firstOrFail();
        try {
            $result = $this->runFixedTest($workspace, $actor, $credential, $version);
        } catch (SafeProviderException $exception) {
            $this->recordTestFailure($workspace, $actor, $credential, $version, $exception);
            throw $exception;
        }
        $credential->forceFill([
            'status' => AiCredentialStatus::Healthy,
            'last_tested_at' => now(),
            'last_healthy_at' => now(),
            'retry_after_at' => null,
            'provider_balance_summary' => $result->balanceSummary,
            'balance_checked_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
            'updated_by_user_id' => $actor->id,
        ])->save();
        $this->events->record($workspace, 'tested', $actor, $credential, $version, toState: 'healthy');

        return $credential->fresh('activeVersion');
    }

    public function setEnabled(Workspace $workspace, User $actor, AiProviderCredential $credential, bool $enabled): AiProviderCredential
    {
        if ($enabled) {
            $tested = $this->test($workspace, $actor, $credential);
            $this->events->record($workspace, 'enabled', $actor, $tested, $tested->activeVersion, 'disabled', 'healthy');

            return $tested;
        }

        $from = $credential->status->value;
        $credential->forceFill(['status' => AiCredentialStatus::Disabled, 'updated_by_user_id' => $actor->id])->save();
        $this->events->record($workspace, 'disabled', $actor, $credential, $credential->activeVersion, $from, 'disabled');

        return $credential->fresh('activeVersion');
    }

    public function remove(Workspace $workspace, User $actor, AiProviderCredential $credential): void
    {
        DB::transaction(function () use ($workspace, $actor, $credential): void {
            $locked = AiProviderCredential::query()->whereKey($credential->id)->lockForUpdate()->firstOrFail();
            foreach ($locked->versions()->lockForUpdate()->get() as $version) {
                $version->forceFill([
                    'encrypted_secret' => null,
                    'status' => 'wiped',
                    'wiped_at' => now(),
                ])->save();
            }
            $from = $locked->status->value;
            $locked->forceFill([
                'active_version_id' => null,
                'status' => AiCredentialStatus::Removed,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->events->record($workspace, 'removed', $actor, $locked, fromState: $from, toState: 'removed');
        }, 3);
    }

    private function validateAndPromote(Workspace $workspace, User $actor, AiProviderCredential $credential, AiProviderCredentialVersion $version, string $event): AiProviderCredential
    {
        try {
            $result = $this->runFixedTest($workspace, $actor, $credential, $version);
        } catch (SafeProviderException $exception) {
            $this->recordTestFailure($workspace, $actor, $credential, $version, $exception);
            throw $exception;
        }

        DB::transaction(function () use ($workspace, $actor, $credential, $version, $event, $result): void {
            $locked = AiProviderCredential::query()->whereKey($credential->id)->lockForUpdate()->firstOrFail();
            $oldVersion = $locked->activeVersion()->lockForUpdate()->first();
            if ($oldVersion !== null && $oldVersion->isNot($version)) {
                $oldVersion->forceFill(['status' => 'retiring', 'retire_after' => now()->addDays(7)])->save();
            }
            $version->forceFill(['status' => 'active', 'tested_at' => now(), 'activated_at' => now()])->save();
            $locked->forceFill([
                'active_version_id' => $version->id,
                'status' => AiCredentialStatus::Healthy,
                'last_tested_at' => now(),
                'last_healthy_at' => now(),
                'retry_after_at' => null,
                'provider_balance_summary' => $result->balanceSummary,
                'balance_checked_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->events->record($workspace, $event, $actor, $locked, $version, toState: 'healthy');
        }, 3);

        return $credential->fresh('activeVersion');
    }

    private function runFixedTest(Workspace $workspace, User $actor, AiProviderCredential $credential, AiProviderCredentialVersion $version): ProviderTestResult
    {
        $price = $credential->provider === AiProviderName::Tavily
            ? (int) AiBudgetSetting::query()->whereKey($workspace->id)->value('tavily_credit_price_micros')
            : (int) config('ai.setup_test_limit_micros');
        $reservation = min((int) config('ai.setup_test_limit_micros'), max(1, $price));
        $key = 'connection-test:'.Str::uuid();
        $fingerprint = $this->budget->fingerprint([
            'provider' => $credential->provider->value,
            'operation' => 'connection_test',
            'credential_version_id' => $version->id,
        ], 'fixed-public-probe');

        $operation = $this->budget->reserve(
            $workspace,
            $credential,
            $version,
            $actor,
            AiTask::ConnectionTest,
            'connection_test',
            'connection_test',
            1,
            $reservation,
            $key,
            $fingerprint,
        );
        [$operation, $attempt] = DB::transaction(function () use ($operation): array {
            $locked = AiProviderOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'status' => AiOperationStatus::Executing,
                'started_at' => now(),
                'lease_owner' => (string) Str::uuid(),
                'fencing_token' => (int) $locked->fencing_token + 1,
                'lease_expires_at' => now()->addMinutes(max(1, (int) config('ai.execution_lease_minutes', 10))),
            ])->save();
            $attempt = AiProviderCallAttempt::query()->create([
                'operation_id' => $locked->id,
                'attempt_number' => 1,
                'kind' => 'execute',
                'status' => 'started',
                'fencing_token' => $locked->fencing_token,
            ]);

            return [$locked->fresh(), $attempt];
        }, 3);

        try {
            $result = $this->providers->for($credential->provider)->test((string) $version->encrypted_secret);
            if (! $this->completeAttempt(
                $operation,
                $attempt,
                $result->providerRequestId,
                $result->providerGenerationId,
                $result->providerResponseId,
            )) {
                throw $this->fencedResultException($operation);
            }
            $actualCostMicros = $credential->provider === AiProviderName::Tavily
                ? $price
                : $result->costMicros;
            $costBasis = $credential->provider === AiProviderName::Tavily ? 'formula_estimate' : $result->costBasis;
            $settled = $this->budget->settleExact(
                operation: $operation,
                actualCostMicros: $actualCostMicros,
                actualNativeUnits: $result->nativeUnits,
                costBasis: $costBasis,
                usage: [
                    'native_unit' => $result->nativeUnit,
                    'raw_provider_cost' => $result->rawProviderCost,
                    'provider_response_id' => $result->providerResponseId,
                    'provider_metadata' => $result->providerMetadata,
                    'cost_evidence_source' => $credential->provider === AiProviderName::Tavily ? 'formula_estimate' : $result->evidenceSource,
                    'provider_request_id' => $result->providerRequestId,
                    'provider_generation_id' => $result->providerGenerationId,
                ],
                leaseOwner: $operation->lease_owner,
                fencingToken: (int) $operation->fencing_token,
            );
            if ($settled === null) {
                // The provider response was recorded, but this worker lost
                // its lease before it could atomically settle the charge.
                // Never expose the response or mark the credential healthy.
                throw $this->fencedResultException($operation);
            }

            return $result;
        } catch (SafeProviderException $exception) {
            $this->failAttempt($operation, $attempt, $exception);
            throw $exception;
        }
    }

    private function completeAttempt(
        AiProviderOperation $operation,
        AiProviderCallAttempt $attempt,
        ?string $requestId,
        ?string $generationId,
        ?string $responseId = null,
    ): bool {
        $updated = AiProviderOperation::query()
            ->whereKey($operation->id)
            ->where('status', AiOperationStatus::Executing)
            ->where('lease_owner', $operation->lease_owner)
            ->where('fencing_token', (int) $operation->fencing_token)
            ->update([
                'provider_request_id' => $requestId,
                'provider_generation_id' => $generationId,
                'safe_request_id' => $requestId,
                'provider_response_id' => $responseId,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            return false;
        }
        $attempt->forceFill([
            'status' => 'completed',
            'provider_request_id' => $requestId,
            'provider_generation_id' => $generationId,
            'fencing_token' => $operation->fencing_token,
        ])->save();

        return true;
    }

    private function fencedResultException(AiProviderOperation $operation): SafeProviderException
    {
        $current = AiProviderOperation::query()->whereKey($operation->id)->first();
        $status = $current?->status;
        if ($status !== null && in_array($status, [
            AiOperationStatus::Succeeded,
            AiOperationStatus::Reconciled,
            AiOperationStatus::ManualReview,
        ], true)) {
            return new SafeProviderException(
                safeCode: 'paid_result_lost',
                safeMessage: 'The provider result arrived after its execution fence was lost; the settled result cannot be persisted or replayed.',
                ambiguous: false,
                safeMetadata: [
                    'reason' => 'execution_fence_lost',
                    'operation_status' => $status->value,
                ],
            );
        }

        return new SafeProviderException(
            safeCode: 'provider_uncertain',
            safeMessage: 'The provider result arrived after its execution fence was lost; the operation requires reconciliation.',
            ambiguous: true,
            safeMetadata: [
                'reason' => 'execution_fence_lost',
                'operation_status' => $status === null ? 'missing' : $status->value,
            ],
        );
    }

    private function failAttempt(
        AiProviderOperation $operation,
        AiProviderCallAttempt $attempt,
        SafeProviderException $exception,
    ): void {
        $attemptStatus = $exception->ambiguous
            ? 'ambiguous'
            : ($exception->safeCode === 'paid_result_lost' ? 'response_received' : 'definitely_not_sent');
        $attempt->forceFill([
            // A lost paid result was received from the provider. It must not
            // be recorded as definitely-not-sent merely because settlement
            // lost the execution fence after completeAttempt().
            'status' => $attemptStatus,
            'safe_error_code' => $exception->safeCode,
            'http_status' => $exception->httpStatus,
            'safe_metadata' => $exception->safeMetadata,
            'retry_after_at' => $exception->retryAfterSeconds === null ? null : now()->addSeconds($exception->retryAfterSeconds),
        ])->save();
        if ($exception->ambiguous) {
            $this->budget->markUncertain(
                $operation,
                $exception->safeCode,
                $exception->getMessage(),
                $operation->lease_owner,
                (int) $operation->fencing_token,
                $exception->retryAfterSeconds,
                $exception->safeMetadata,
            );
        } else {
            $this->budget->releaseDefinitelyNotSent(
                $operation,
                $exception->safeCode,
                $exception->getMessage(),
                $operation->lease_owner,
                (int) $operation->fencing_token,
                $exception->retryAfterSeconds,
                $exception->safeMetadata,
            );
        }
    }

    private function recordTestFailure(
        Workspace $workspace,
        User $actor,
        AiProviderCredential $credential,
        AiProviderCredentialVersion $version,
        SafeProviderException $exception,
    ): void {
        $from = $credential->status->value;
        $status = $exception->safeCode === 'rate_limited'
            ? AiCredentialStatus::RateLimited
            : AiCredentialStatus::Misconfigured;
        $credential->forceFill([
            'status' => $status,
            'last_tested_at' => now(),
            'retry_after_at' => $exception->retryAfterSeconds === null ? null : now()->addSeconds($exception->retryAfterSeconds),
            'last_error_code' => $exception->safeCode,
            'last_error_message' => $exception->getMessage(),
            'updated_by_user_id' => $actor->id,
        ])->save();
        $this->events->record($workspace, 'health_changed', $actor, $credential, $version, $from, $status->value);
    }
}
