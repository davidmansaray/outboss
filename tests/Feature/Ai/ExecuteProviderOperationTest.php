<?php

use App\Domain\Ai\Contracts\AiProviderAdapter;
use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResult;
use App\Domain\Ai\DTOs\ProviderTestResult;
use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Exceptions\SafeProviderException;
use App\Domain\Ai\Providers\AiProviderRegistry;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Ai\Services\ExecuteProviderOperation;
use App\Models\AiProviderCallAttempt;
use App\Models\AiProviderCredential;
use App\Models\AiProviderOperation;
use App\Models\AiUsageLedger;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('reserves, calls once, and settles a bounded LLM operation', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => 'llm',
        'provider' => AiProviderName::OpenRouter,
        'status' => AiCredentialStatus::Healthy,
    ]);
    $version = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => 'sk-or-execution-secret',
        'status' => 'active',
    ]);
    $credential->forceFill(['active_version_id' => $version->id])->save();
    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-execute',
            'choices' => [['message' => ['content' => '{"score": 92}']]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 8, 'cost' => 0.001],
        ], 200, ['x-request-id' => 'req-execute']),
    ]);

    $service = app(ExecuteProviderOperation::class);
    $result = $service->generate($workspace, $user, AiTask::Qualification, 'Score this public company.', 'execute-once');

    expect($result->content)->toBe('{"score": 92}')
        ->and(AiUsageLedger::query()->sole()->cost_micros)->toBe(1_000)
        ->and(AiProviderCallAttempt::query()->sole()->status)->toBe('completed');

    expect(fn () => $service->generate($workspace, $user, AiTask::Qualification, 'Score this public company.', 'execute-once'))
        ->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
});

it('never returns a result after a second worker fences the in-flight operation', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => 'llm',
        'provider' => AiProviderName::OpenRouter,
        'status' => AiCredentialStatus::Healthy,
    ]);
    $version = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => 'sk-or-stale-fence-secret',
        'status' => 'active',
    ]);
    $credential->forceFill(['active_version_id' => $version->id])->save();

    $staleProvider = new class($workspace) implements AiProviderAdapter, LlmProvider
    {
        public function __construct(private readonly Workspace $workspace) {}

        public function test(string $secret): ProviderTestResult
        {
            throw new RuntimeException('The test path is not used by this regression.');
        }

        public function reconcile(string $secret, AiProviderOperation $operation): ?ProviderTestResult
        {
            return null;
        }

        public function generate(string $secret, LlmRequest $request): LlmResult
        {
            $operation = AiProviderOperation::query()
                ->where('workspace_id', $this->workspace->id)
                ->where('status', AiOperationStatus::Executing)
                ->firstOrFail();
            $operation->forceFill([
                'status' => AiOperationStatus::Uncertain,
                'fencing_token' => (int) $operation->fencing_token + 1,
                'lease_owner' => null,
                'lease_expires_at' => null,
            ])->save();

            return new LlmResult(
                content: '{"result":"stale"}',
                inputTokens: 10,
                outputTokens: 4,
                reasoningTokens: 0,
                cacheTokens: 0,
                costMicros: 100,
                costBasis: 'provider_exact',
                providerRequestId: 'stale-request',
                providerGenerationId: 'stale-generation',
                rawProviderCost: '0.000100',
                providerResponseId: 'stale-response',
                totalTokens: 14,
            );
        }
    };
    $unusedTavily = new class implements AiProviderAdapter
    {
        public function test(string $secret): ProviderTestResult
        {
            throw new RuntimeException('The test path is not used by this regression.');
        }

        public function reconcile(string $secret, AiProviderOperation $operation): ?ProviderTestResult
        {
            return null;
        }
    };
    app()->instance(AiProviderRegistry::class, new AiProviderRegistry([
        'openrouter' => $staleProvider,
        'tavily' => $unusedTavily,
    ]));

    $exception = null;
    try {
        app(ExecuteProviderOperation::class)->generate(
            $workspace,
            $user,
            AiTask::Qualification,
            'A bounded stale-fence request.',
            'stale-fence-operation',
        );
    } catch (SafeProviderException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(SafeProviderException::class)
        ->and($exception?->safeCode)->toBe('provider_uncertain')
        ->and($exception?->ambiguous)->toBeTrue()
        ->and(AiProviderOperation::query()->sole()->status)->toBe(AiOperationStatus::Uncertain)
        ->and(AiProviderCallAttempt::query()->sole()->status)->toBe('ambiguous')
        ->and(AiUsageLedger::query()->count())->toBe(0);
});

it('does not return a completed result when its settlement fence is lost', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => 'llm',
        'provider' => AiProviderName::OpenRouter,
        'status' => AiCredentialStatus::Healthy,
    ]);
    $version = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => 'sk-or-post-completion-fence-secret',
        'status' => 'active',
    ]);
    $credential->forceFill(['active_version_id' => $version->id])->save();
    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-post-completion-fence',
            'choices' => [['message' => ['content' => '{"result":"must-not-leak"}']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4, 'cost' => 0.0001],
        ], 200, ['x-request-id' => 'req-post-completion-fence']),
    ]);

    $racingBudget = new class extends AiBudgetGateway
    {
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
            // This is the deterministic equivalent of a reconciler fencing
            // the worker after completeAttempt() but before settlement.
            $replacement = $operation->fresh();
            $replacement->forceFill([
                'status' => AiOperationStatus::Executing,
                'fencing_token' => (int) $operation->fencing_token + 1,
                'lease_owner' => 'replacement-worker',
                'lease_expires_at' => now()->addMinute(),
            ])->save();
            // Complete the replacement worker's settlement. The original
            // worker must still receive no proof for its old fence.
            parent::settle(
                $operation,
                $actualCostMicros,
                $actualNativeUnits,
                $costBasis,
                $usage,
                $finalStatus,
                'replacement-worker',
                (int) $replacement->fencing_token,
            );

            return parent::settleExact(
                $operation,
                $actualCostMicros,
                $actualNativeUnits,
                $costBasis,
                $usage,
                $finalStatus,
                $leaseOwner,
                $fencingToken,
            );
        }
    };
    app()->instance(AiBudgetGateway::class, $racingBudget);

    $exception = null;
    try {
        app(ExecuteProviderOperation::class)->generate(
            $workspace,
            $user,
            AiTask::Qualification,
            'A bounded post-completion fence request.',
            'post-completion-fence-operation',
        );
    } catch (SafeProviderException $caught) {
        $exception = $caught;
    }

    $operation = AiProviderOperation::query()->sole();
    $attempt = AiProviderCallAttempt::query()->sole();
    expect($exception)->toBeInstanceOf(SafeProviderException::class)
        ->and($exception?->safeCode)->toBe('paid_result_lost')
        ->and($exception?->ambiguous)->toBeFalse()
        ->and($operation->status)->toBe(AiOperationStatus::Succeeded)
        ->and($operation->provider_request_id)->toBe('req-post-completion-fence')
        ->and($operation->actual_cost_micros)->toBe(100)
        ->and($operation->cost_basis)->toBe('provider_exact')
        ->and($attempt->status)->toBe('response_received')
        ->and($attempt->status)->not->toBe('definitely_not_sent')
        ->and($attempt->safe_error_code)->toBe('paid_result_lost')
        ->and(AiUsageLedger::query()->sole()->cost_micros)->toBe(100)
        ->and($operation->provider_metadata)->not->toHaveKey('content');
    Http::assertSentCount(1);
});
