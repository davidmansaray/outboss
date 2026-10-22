<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\Contracts\ResearchProvider;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResult;
use App\Domain\Ai\DTOs\ResearchExtractRequest;
use App\Domain\Ai\DTOs\ResearchResult;
use App\Domain\Ai\DTOs\ResearchSearchRequest;
use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Exceptions\SafeProviderException;
use App\Domain\Ai\Providers\AiProviderRegistry;
use App\Domain\Ai\Providers\TavilyAdapter;
use App\Models\AiBudgetSetting;
use App\Models\AiProviderCallAttempt;
use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialVersion;
use App\Models\AiProviderOperation;
use App\Models\AiTaskSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ExecuteProviderOperation
{
    public function __construct(
        private readonly AiProviderRegistry $providers,
        private readonly AiBudgetGateway $budget,
        private readonly AiCostEstimator $costs,
        private readonly AiWorkspaceSettings $settings,
        private readonly ProviderErrorRedactor $redactor,
    ) {}

    public function generate(Workspace $workspace, User $actor, AiTask $task, string $content, string $idempotencyKey, ?string $runId = null): LlmResult
    {
        // Keep generic callers on the current policy while validating the
        // bounded input before any settings rows are read or created.
        $this->generationDefinition($task, $content);
        $policy = $this->currentGenerationPolicy($workspace, $task);

        return $this->generateForPolicy(
            $workspace,
            $actor,
            $task,
            $content,
            $idempotencyKey,
            (string) $policy->model_id,
            (int) $policy->policy_version,
            $runId,
        );
    }

    /** Execute only when the exact durable model policy is still active. */
    public function generateForPolicy(
        Workspace $workspace,
        User $actor,
        AiTask $task,
        string $content,
        string $idempotencyKey,
        string $modelId,
        int $policyVersion,
        ?string $runId = null,
    ): LlmResult {
        $definition = $this->generationDefinition($task, $content);
        $operation = $this->reserveGenerationForPolicy(
            $workspace,
            $actor,
            $task,
            $content,
            $idempotencyKey,
            $modelId,
            $policyVersion,
            $runId,
        );
        $version = AiProviderCredentialVersion::query()
            ->where('credential_id', $operation->credential_id)
            ->whereKey($operation->credential_version_id)
            ->firstOrFail();
        [$operation, $attempt] = $this->claim($operation);
        $adapter = $this->providers->for(AiProviderName::OpenRouter);
        if (! $adapter instanceof LlmProvider) {
            throw new RuntimeException('The configured LLM provider does not implement generation.');
        }

        try {
            $schemaDefinition = $definition['response_schema'] ?? null;
            $result = $adapter->generate((string) $version->encrypted_secret, new LlmRequest(
                modelId: (string) $operation->model_or_endpoint,
                content: $content,
                maximumOutputTokens: (int) $definition['maximum_output_tokens'],
                responseSchemaName: is_array($schemaDefinition) ? ($schemaDefinition['name'] ?? null) : null,
                responseSchema: is_array($schemaDefinition) ? ($schemaDefinition['schema'] ?? null) : null,
            ));
            if (! $this->completeAttempt($operation, $attempt, $result->providerRequestId, $result->providerGenerationId, $result->providerResponseId)) {
                // A reconciler fenced this worker while the provider call was
                // in flight. Never expose the stale result to a caller: the
                // operation may already be settled, or it may still require
                // reconciliation. In either case the original call must not
                // be repeated with a new key.
                throw $this->fencedResultException($operation);
            }
            $settled = $this->budget->settleExact(
                operation: $operation,
                actualCostMicros: $result->costMicros,
                actualNativeUnits: $result->totalTokens ?? ($result->inputTokens + $result->outputTokens),
                costBasis: $result->costBasis,
                usage: [
                    'native_unit' => 'tokens',
                    'input_tokens' => $result->inputTokens,
                    'output_tokens' => $result->outputTokens,
                    'reasoning_tokens' => $result->reasoningTokens,
                    'cache_tokens' => $result->cacheTokens,
                    'raw_provider_cost' => $result->rawProviderCost,
                    'provider_response_id' => $result->providerResponseId,
                    'provider_metadata' => $result->providerMetadata,
                    'cost_evidence_source' => 'response',
                    'provider_request_id' => $result->providerRequestId,
                    'provider_generation_id' => $result->providerGenerationId,
                ],
                leaseOwner: $operation->lease_owner,
                fencingToken: (int) $operation->fencing_token,
            );
            if ($settled === null) {
                // The provider response was recorded, but this worker lost
                // its lease before it could atomically settle the charge.
                // Never expose the response or retry a call that may be paid.
                throw $this->fencedResultException($operation);
            }

            return $result;
        } catch (SafeProviderException $exception) {
            $this->failAttempt($operation, $attempt, $exception);
            throw $exception;
        } catch (Throwable $exception) {
            $safe = $this->redactor->from($exception, true, AiProviderName::OpenRouter);
            $this->failAttempt($operation, $attempt, $safe);
            throw $safe;
        }
    }

    /**
     * Reserve a generation without crossing the provider boundary.
     *
     * Orchestrators use this to admit a bounded batch transactionally before
     * any member of that batch is allowed to claim its provider operation.
     */
    public function reserveGeneration(Workspace $workspace, User $actor, AiTask $task, string $content, string $idempotencyKey, ?string $runId = null): AiProviderOperation
    {
        // Preserve the validate-before-settings invariant for generic callers.
        $this->generationDefinition($task, $content);
        $policy = $this->currentGenerationPolicy($workspace, $task);

        return $this->reserveGenerationForPolicy(
            $workspace,
            $actor,
            $task,
            $content,
            $idempotencyKey,
            (string) $policy->model_id,
            (int) $policy->policy_version,
            $runId,
        );
    }

    /** Reserve only against the supplied, previously frozen model policy. */
    public function reserveGenerationForPolicy(
        Workspace $workspace,
        User $actor,
        AiTask $task,
        string $content,
        string $idempotencyKey,
        string $modelId,
        int $policyVersion,
        ?string $runId = null,
    ): AiProviderOperation {
        $this->settings->ensureDefaults($workspace);
        $definition = $this->generationDefinition($task, $content);
        $estimate = $this->costs->openRouter($modelId, strlen($content), (int) $definition['maximum_output_tokens']);
        [$credential, $version] = $this->activeCredential($workspace, AiProviderName::OpenRouter);

        return $this->budget->reserve(
            $workspace,
            $credential,
            $version,
            $actor,
            $task,
            'completion',
            $modelId,
            $estimate->nativeUnits,
            $estimate->costMicros,
            $idempotencyKey,
            $this->budget->fingerprint([
                'task' => $task->value,
                'model' => $modelId,
                'policy_version' => $policyVersion,
                'maximum_output_tokens' => $definition['maximum_output_tokens'],
                'schema' => $definition['response_schema']['name'] ?? null,
            ], $content),
            $runId,
            null,
            $policyVersion,
        );
    }

    private function currentGenerationPolicy(Workspace $workspace, AiTask $task): AiTaskSetting
    {
        $this->settings->ensureDefaults($workspace);

        return AiTaskSetting::query()
            ->where('workspace_id', $workspace->id)
            ->where('task', $task->value)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function generationDefinition(AiTask $task, string $content): array
    {
        $definition = config("ai.tasks.{$task->value}");
        if (! is_array($definition) || strlen($content) > (int) $definition['maximum_input_bytes']) {
            throw new RuntimeException('The task input exceeds its bounded policy.');
        }

        return $definition;
    }

    public function search(Workspace $workspace, User $actor, ResearchSearchRequest $request, string $idempotencyKey, ?string $runId = null): ResearchResult
    {
        // Validate before settings lookup, reservation, or operation claim.
        $adapter = $this->providers->for(AiProviderName::Tavily);
        if (! $adapter instanceof ResearchProvider) {
            throw new RuntimeException('The configured research provider does not implement research.');
        }
        if ($adapter instanceof TavilyAdapter) {
            $adapter->validateSearchRequest($request);
        }
        $price = $this->tavilyPrice($workspace);
        $estimate = $this->costs->tavilySearch($request->depth, $price);

        return $this->research($workspace, $actor, $request, 'search', $estimate->nativeUnits, $estimate->costMicros, $idempotencyKey, $runId);
    }

    public function extract(Workspace $workspace, User $actor, ResearchExtractRequest $request, string $idempotencyKey, ?string $runId = null): ResearchResult
    {
        // URL/public-network checks run before a budget hold is created.
        $adapter = $this->providers->for(AiProviderName::Tavily);
        if (! $adapter instanceof ResearchProvider) {
            throw new RuntimeException('The configured research provider does not implement research.');
        }
        if ($adapter instanceof TavilyAdapter) {
            $adapter->validateExtractRequest($request);
        }
        $price = $this->tavilyPrice($workspace);
        $estimate = $this->costs->tavilyExtract(count($request->urls), $request->depth, $price);

        return $this->research($workspace, $actor, $request, 'extract', $estimate->nativeUnits, $estimate->costMicros, $idempotencyKey, $runId);
    }

    private function research(Workspace $workspace, User $actor, ResearchSearchRequest|ResearchExtractRequest $request, string $kind, int $credits, int $costMicros, string $idempotencyKey, ?string $runId): ResearchResult
    {
        $this->settings->ensureDefaults($workspace);
        [$credential, $version] = $this->activeCredential($workspace, AiProviderName::Tavily);
        $content = $request instanceof ResearchSearchRequest ? $request->query : implode("\n", $request->urls);
        $operation = $this->budget->reserve(
            $workspace,
            $credential,
            $version,
            $actor,
            AiTask::AccountResearch,
            $kind,
            "tavily/{$kind}",
            $credits,
            $costMicros,
            $idempotencyKey,
            $this->budget->fingerprint(['task' => 'account_research', 'endpoint' => $kind, 'depth' => $request->depth], $content),
            $runId,
        );
        [$operation, $attempt] = $this->claim($operation);
        $adapter = $this->providers->for(AiProviderName::Tavily);
        if (! $adapter instanceof ResearchProvider) {
            throw new RuntimeException('The configured research provider does not implement research.');
        }

        try {
            $result = $request instanceof ResearchSearchRequest
                ? $adapter->search((string) $version->encrypted_secret, $request)
                : $adapter->extract((string) $version->encrypted_secret, $request);
            if (! $this->completeAttempt($operation, $attempt, $result->providerRequestId, null)) {
                // See the generation path above. A result from a worker that
                // no longer owns the operation fence is never caller-visible
                // and can never be used to create durable evidence.
                throw $this->fencedResultException($operation);
            }
            // A reported zero can be a pre-threshold Extract value. Keep the
            // declared upper bound until provider logs prove a lower charge.
            $actualCredits = $result->reportedCredits !== null && $result->reportedCredits > 0
                ? $result->reportedCredits
                : $result->credits;
            $actualCost = $actualCredits * $this->tavilyPrice($workspace);
            $costBasis = $result->reportedCredits !== null && $result->reportedCredits > 0
                ? 'provider_exact'
                : 'formula_estimate';
            $settled = $this->budget->settleExact(
                operation: $operation,
                actualCostMicros: $actualCost,
                actualNativeUnits: $actualCredits,
                costBasis: $costBasis,
                usage: [
                    'native_unit' => 'credits',
                    'provider_metadata' => $result->providerMetadata,
                    'cost_evidence_source' => $result->reportedCredits !== null && $result->reportedCredits > 0 ? 'response' : 'formula_estimate',
                    'provider_request_id' => $result->providerRequestId,
                ],
                leaseOwner: $operation->lease_owner,
                fencingToken: (int) $operation->fencing_token,
            );
            if ($settled === null) {
                // The provider response was recorded, but this worker lost
                // its lease before it could atomically settle the charge.
                // Never expose the response or retry a call that may be paid.
                throw $this->fencedResultException($operation);
            }

            return $result;
        } catch (SafeProviderException $exception) {
            $this->failAttempt($operation, $attempt, $exception);
            throw $exception;
        } catch (Throwable $exception) {
            $safe = $this->redactor->from($exception, true, AiProviderName::Tavily);
            $this->failAttempt($operation, $attempt, $safe);
            throw $safe;
        }
    }

    /** @return array{AiProviderCredential, AiProviderCredentialVersion} */
    private function activeCredential(Workspace $workspace, AiProviderName $provider): array
    {
        $credential = AiProviderCredential::query()
            ->where('workspace_id', $workspace->id)
            ->where('provider', $provider->value)
            ->where('status', 'healthy')
            ->with('activeVersion')
            ->firstOrFail();
        $version = $credential->activeVersion;
        if ($version === null || $version->encrypted_secret === null || $version->wiped_at !== null) {
            throw new RuntimeException('The active provider credential is unavailable.');
        }

        return [$credential, $version];
    }

    /** @return array{AiProviderOperation, AiProviderCallAttempt} */
    private function claim(AiProviderOperation $operation): array
    {
        return DB::transaction(function () use ($operation): array {
            $credentialId = AiProviderOperation::query()->whereKey($operation->id)->value('credential_id');
            if ($credentialId === null) {
                throw new RuntimeException('The provider operation no longer exists.');
            }
            $credential = AiProviderCredential::query()->whereKey($credentialId)->lockForUpdate()->firstOrFail();
            $locked = AiProviderOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== AiOperationStatus::Reserved) {
                throw new RuntimeException('The provider operation has already been claimed or completed.');
            }
            if ($credential->status->value !== 'healthy' || (int) $credential->active_version_id !== (int) $locked->credential_version_id) {
                $this->budget->releaseDefinitelyNotSentWithinTransaction($locked, 'pre_call_blocked', 'Provider work was blocked before network activity.');
                throw new RuntimeException('Provider work was blocked before network activity.');
            }
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
    }

    private function completeAttempt(AiProviderOperation $operation, AiProviderCallAttempt $attempt, ?string $requestId, ?string $generationId, ?string $responseId = null): bool
    {
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

    private function failAttempt(AiProviderOperation $operation, AiProviderCallAttempt $attempt, SafeProviderException $exception): void
    {
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

    private function tavilyPrice(Workspace $workspace): int
    {
        $this->settings->ensureDefaults($workspace);

        return (int) AiBudgetSetting::query()->whereKey($workspace->id)->value('tavily_credit_price_micros');
    }
}
