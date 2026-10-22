<?php

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Ai\Services\ReconcileAiProviderOperations;
use App\Models\AiBudgetPeriod;
use App\Models\AiProviderCallAttempt;
use App\Models\AiProviderCredential;
use App\Models\AiProviderOperation;
use App\Models\AiUsageLedger;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function reservableAiOperation(User $user, AiProviderName $provider = AiProviderName::OpenRouter): array
{
    $workspace = $user->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => $provider->capability(),
        'provider' => $provider,
        'status' => AiCredentialStatus::Healthy,
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
    ]);
    $version = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => 'test-reconciliation-secret',
        'status' => 'active',
        'created_by_user_id' => $user->id,
        'tested_at' => now(),
        'activated_at' => now(),
    ]);
    $credential->forceFill(['active_version_id' => $version->id])->save();
    $gateway = app(AiBudgetGateway::class);
    $operation = $gateway->reserve(
        $workspace,
        $credential->fresh(),
        $version,
        $user,
        AiTask::Qualification,
        'completion',
        'openai/gpt-5-mini',
        2_000,
        100_000,
        'reconcile-'.fake()->uuid(),
        $gateway->fingerprint(['task' => 'qualification'], 'private content'),
    );

    return [$operation, $gateway];
}

/** @return array{0: AiProviderOperation, 1: AiBudgetGateway} */
function reservableTavilyAiOperation(User $user): array
{
    $workspace = $user->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => AiProviderName::Tavily->capability(),
        'provider' => AiProviderName::Tavily,
        'status' => AiCredentialStatus::Healthy,
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
    ]);
    $version = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => 'test-tavily-reconciliation-secret',
        'status' => 'active',
        'created_by_user_id' => $user->id,
        'tested_at' => now(),
        'activated_at' => now(),
    ]);
    $credential->forceFill(['active_version_id' => $version->id])->save();
    $gateway = app(AiBudgetGateway::class);
    $operation = $gateway->reserve(
        $workspace,
        $credential->fresh(),
        $version,
        $user,
        AiTask::AccountResearch,
        'search',
        'tavily/search',
        2,
        16_000,
        'tavily-reconcile-'.fake()->uuid(),
        $gateway->fingerprint(['task' => 'account_research', 'endpoint' => 'search'], 'public company facts'),
    );

    return [$operation, $gateway];
}

it('reconciles OpenRouter generation cost without repeating the original provider call', function () {
    [$operation, $gateway] = reservableAiOperation(User::factory()->create());
    $operation = $operation->fresh();
    $operation->forceFill([
        'provider_generation_id' => 'gen-reconcile-1',
        'provider_request_id' => 'req-reconcile-1',
    ])->save();
    $gateway->markUncertain($operation, 'provider_unavailable', 'The response was lost.')
        ->forceFill(['reconcile_after' => now()->subMinute()])
        ->save();

    Http::fake([
        'https://openrouter.ai/api/v1/generation*' => Http::response([
            'data' => [
                'total_cost' => 0.042,
                'tokens_prompt' => 900,
                'tokens_completion' => 100,
            ],
        ]),
    ]);

    expect(app(ReconcileAiProviderOperations::class)->handle($operation->id))->toBe(1);

    $operation->refresh();
    expect($operation->status)->toBe(AiOperationStatus::Reconciled)
        ->and($operation->actual_cost_micros)->toBe(42_000)
        ->and(AiBudgetPeriod::query()->sole()->held_micros)->toBe(0)
        ->and(AiBudgetPeriod::query()->sole()->committed_micros)->toBe(42_000)
        ->and(AiUsageLedger::query()->sole()->cost_basis)->toBe('provider_exact')
        ->and(AiProviderCallAttempt::query()->where('kind', 'reconcile')->count())->toBe(1);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/generation?id=gen-reconcile-1'));
});

it('retains an uncertain hold until the deadline then finalizes once at the upper bound', function () {
    [$operation, $gateway] = reservableAiOperation(User::factory()->create());
    $gateway->markUncertain($operation, 'provider_unavailable', 'The response was lost.');

    $service = app(ReconcileAiProviderOperations::class);
    $operation->forceFill([
        'reconcile_after' => now()->subMinute(),
        'finalize_after' => now()->addHour(),
    ])->save();
    expect($service->handle($operation->id))->toBe(1)
        ->and($operation->fresh()->status)->toBe(AiOperationStatus::Uncertain)
        ->and(AiBudgetPeriod::query()->sole()->held_micros)->toBe(100_000)
        ->and(AiUsageLedger::query()->count())->toBe(0);

    $operation = $operation->fresh();
    $operation->forceFill([
        'reconcile_after' => now()->subMinute(),
        'finalize_after' => now()->subSecond(),
    ])->save();
    expect($service->handle($operation->id))->toBe(1)
        ->and($operation->fresh()->status)->toBe(AiOperationStatus::ManualReview)
        ->and(AiBudgetPeriod::query()->sole()->held_micros)->toBe(0)
        ->and(AiBudgetPeriod::query()->sole()->committed_micros)->toBe(100_000)
        ->and(AiUsageLedger::query()->sole()->cost_basis)->toBe('upper_bound_estimate');

    expect($service->handle($operation->id))->toBe(0)
        ->and(AiUsageLedger::query()->count())->toBe(1);
});

it('reconciles Tavily paid logs by exact request, endpoint, project and credential filter', function () {
    [$operation, $gateway] = reservableTavilyAiOperation(User::factory()->create());
    $operation->forceFill(['provider_request_id' => 'tv-log-request'])->save();
    $gateway->markUncertain($operation, 'provider_unavailable', 'The response was lost.')
        ->forceFill(['reconcile_after' => now()->subMinute()])
        ->save();

    Http::fake([
        'https://api.tavily.com/logs' => Http::response([
            'logs' => [[
                'request_id' => 'tv-log-request',
                'endpoint' => 'search',
                'project_id' => 'outboss-ai',
                'credits' => 2,
                'response_time' => 0.42,
            ]],
            'count' => 1,
        ]),
    ]);

    expect(app(ReconcileAiProviderOperations::class)->handle($operation->id))->toBe(1);

    $operation->refresh();
    expect($operation->status)->toBe(AiOperationStatus::Reconciled)
        ->and($operation->actual_native_units)->toBe(2)
        ->and($operation->actual_cost_micros)->toBe(16_000)
        ->and($operation->safe_request_id)->toBe('tv-log-request')
        ->and(AiBudgetPeriod::query()->sole()->held_micros)->toBe(0)
        ->and(AiUsageLedger::query()->sole()->cost_evidence_source)->toBe('provider_logs');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request['endpoints'] === ['search']
        && $request['project_id'] === 'outboss-ai'
        && $request['filter_by_api_key'] === true
        && ! isset($request['request_id'])
        && $request->hasHeader('X-Project-ID', 'outboss-ai'));
});

it('fences an expired executing worker before reconciling and ignores its stale settlement', function () {
    [$operation, $gateway] = reservableAiOperation(User::factory()->create());
    $operation->forceFill([
        'status' => AiOperationStatus::Executing,
        'provider_generation_id' => 'gen-expired-worker',
        'provider_request_id' => 'req-before-expiry',
        'lease_owner' => 'worker-old',
        'fencing_token' => 7,
        'lease_expires_at' => now()->subMinute(),
        'started_at' => now()->subMinutes(2),
        'finalize_after' => now()->addHour(),
    ])->save();
    AiProviderCallAttempt::query()->create([
        'operation_id' => $operation->id,
        'attempt_number' => 1,
        'kind' => 'execute',
        'status' => 'started',
        'fencing_token' => 7,
    ]);

    Http::fake([
        'https://openrouter.ai/api/v1/generation*' => Http::response([
            'data' => [
                'id' => 'gen-expired-worker',
                'request_id' => 'req-authoritative',
                'tokens_prompt' => 90,
                'tokens_completion' => 10,
                'total_cost' => '0.003001',
            ],
        ]),
    ]);

    expect(app(ReconcileAiProviderOperations::class)->handle($operation->id))->toBe(1);

    $operation->refresh();
    expect($operation->status)->toBe(AiOperationStatus::Reconciled)
        ->and($operation->fencing_token)->toBe(9)
        ->and($operation->provider_request_id)->toBe('req-authoritative')
        ->and($operation->actual_native_units)->toBe(100)
        ->and($operation->actual_cost_micros)->toBe(3_001)
        ->and(AiProviderCallAttempt::query()->where('kind', 'execute')->sole()->status)->toBe('ambiguous')
        ->and(AiProviderCallAttempt::query()->where('kind', 'reconcile')->sole()->status)->toBe('completed');

    $gateway->settle($operation, 999_999, 999, 'provider_exact', [], leaseOwner: 'worker-old', fencingToken: 7);
    expect($operation->fresh()->actual_cost_micros)->toBe(3_001)
        ->and(Http::recorded())->toHaveCount(1);
});
