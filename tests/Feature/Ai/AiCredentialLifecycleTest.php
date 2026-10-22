<?php

use App\Domain\Ai\Contracts\AiProviderAdapter;
use App\Domain\Ai\DTOs\ProviderTestResult;
use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Exceptions\SafeProviderException;
use App\Domain\Ai\Providers\AiProviderRegistry;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Ai\Services\AiCredentialManager;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Ai\Services\WipeRetiredAiCredentials;
use App\Models\AiBudgetPeriod;
use App\Models\AiProviderCallAttempt;
use App\Models\AiProviderCredential;
use App\Models\AiProviderOperation;
use App\Models\AiUsageLedger;
use App\Models\User;

it('retains a retiring secret only while nonterminal work references it and never beyond seven days', function () {
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
        'encrypted_secret' => 'retiring-private-key',
        'status' => 'active',
        'activated_at' => now(),
    ]);
    $credential->forceFill(['active_version_id' => $version->id])->save();
    $operation = app(AiBudgetGateway::class)->reserve(
        $workspace,
        $credential->fresh(),
        $version,
        $user,
        AiTask::Qualification,
        'completion',
        'openai/gpt-5-mini',
        100,
        1_000,
        'retiring-key-operation',
        app(AiBudgetGateway::class)->fingerprint(['task' => 'qualification'], 'content'),
    );
    $version->forceFill(['status' => 'retiring', 'retire_after' => now()->addDays(7)])->save();

    $service = app(WipeRetiredAiCredentials::class);
    expect($service->handle())->toBe(0)
        ->and($version->fresh()->encrypted_secret)->toBe('retiring-private-key');

    $operation->forceFill(['status' => AiOperationStatus::Cancelled, 'finished_at' => now()])->save();
    expect($service->handle())->toBe(1)
        ->and($version->fresh()->encrypted_secret)->toBeNull()
        ->and($version->fresh()->status)->toBe('wiped');
});

it('withholds a fixed-test result when its post-response settlement fence is lost', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    $lastHealthyAt = now()->subDay()->startOfSecond();
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => 'llm',
        'provider' => AiProviderName::OpenRouter,
        'status' => AiCredentialStatus::Healthy,
        'last_healthy_at' => $lastHealthyAt,
    ]);
    $version = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => 'fixed-test-fence-secret',
        'status' => 'active',
    ]);
    $credential->forceFill(['active_version_id' => $version->id])->save();

    $provider = new class implements AiProviderAdapter
    {
        public function test(string $secret): ProviderTestResult
        {
            return new ProviderTestResult(
                nativeUnits: 12,
                nativeUnit: 'tokens',
                costMicros: 100,
                costBasis: 'provider_exact',
                providerRequestId: 'req-fixed-post-fence',
                providerGenerationId: 'gen-fixed-post-fence',
                rawProviderCost: '0.000100',
                evidenceSource: 'response',
                providerMetadata: ['probe' => 'fixed-test'],
                providerResponseId: 'resp-fixed-post-fence',
            );
        }

        public function reconcile(string $secret, AiProviderOperation $operation): ?ProviderTestResult
        {
            return null;
        }
    };
    app()->instance(AiProviderRegistry::class, new AiProviderRegistry([
        'openrouter' => $provider,
    ]));

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
            // Model a reconciler replacing this worker after the provider
            // response was recorded but before the original settlement.
            $replacement = $operation->fresh();
            $replacement->forceFill([
                'status' => AiOperationStatus::Executing,
                'fencing_token' => (int) $operation->fencing_token + 1,
                'lease_owner' => 'replacement-worker',
                'lease_expires_at' => now()->addMinute(),
            ])->save();
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
        app(AiCredentialManager::class)->test($workspace, $user, $credential);
    } catch (SafeProviderException $caught) {
        $exception = $caught;
    }

    $operation = AiProviderOperation::query()->sole();
    $attempt = AiProviderCallAttempt::query()->sole();
    $credential->refresh();
    expect($exception)->toBeInstanceOf(SafeProviderException::class)
        ->and($exception?->safeCode)->toBe('paid_result_lost')
        ->and($exception?->ambiguous)->toBeFalse()
        ->and($credential->status)->toBe(AiCredentialStatus::Misconfigured)
        ->and($credential->last_error_code)->toBe('paid_result_lost')
        ->and($credential->last_healthy_at->toDateTimeString())->toBe($lastHealthyAt->toDateTimeString())
        ->and($operation->status)->toBe(AiOperationStatus::Succeeded)
        ->and($operation->provider_request_id)->toBe('req-fixed-post-fence')
        ->and($operation->provider_generation_id)->toBe('gen-fixed-post-fence')
        ->and($operation->provider_response_id)->toBe('resp-fixed-post-fence')
        ->and($operation->actual_cost_micros)->toBe(100)
        ->and($operation->cost_basis)->toBe('provider_exact')
        ->and($attempt->status)->toBe('response_received')
        ->and($attempt->status)->not->toBe('definitely_not_sent')
        ->and($attempt->safe_error_code)->toBe('paid_result_lost')
        ->and(AiBudgetPeriod::query()->sole()->held_micros)->toBe(0)
        ->and(AiBudgetPeriod::query()->sole()->committed_micros)->toBe(100)
        ->and(AiUsageLedger::query()->sole()->cost_micros)->toBe(100);
});
