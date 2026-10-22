<?php

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Models\AiBudgetPeriod;
use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialVersion;
use App\Models\AiProviderOperation;
use App\Models\AiUsageLedger;
use App\Models\User;

/** @return array{AiProviderCredential, AiProviderCredentialVersion} */
function aiBudgetRunCredential(User $user): array
{
    $workspace = $user->currentWorkspace();
    $credential = AiProviderCredential::query()->create([
        'workspace_id' => $workspace->id,
        'capability' => 'llm',
        'provider' => AiProviderName::OpenRouter,
        'status' => AiCredentialStatus::Healthy,
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
    ]);
    $version = $credential->versions()->create([
        'version' => 1,
        'encrypted_secret' => 'test-budget-run-secret',
        'status' => 'active',
        'created_by_user_id' => $user->id,
        'tested_at' => now(),
        'activated_at' => now(),
    ]);
    $credential->forceFill(['active_version_id' => $version->id])->save();

    return [$credential->fresh(), $version];
}

it('keeps an orchestration-owned run open across settled operations until explicitly closed', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    [$credential, $version] = aiBudgetRunCredential($user);
    $gateway = app(AiBudgetGateway::class);

    $run = $gateway->openRun($workspace, $user, AiTask::Qualification);
    $first = $gateway->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::Qualification,
        'completion',
        'openai/gpt-5-mini',
        2_000,
        80_000,
        'orchestration-operation-1',
        $gateway->fingerprint(['operation' => 1], 'content-1'),
        $run->id,
    );
    $second = $gateway->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::Qualification,
        'completion',
        'openai/gpt-5-mini',
        2_000,
        60_000,
        'orchestration-operation-2',
        $gateway->fingerprint(['operation' => 2], 'content-2'),
        $run->id,
    );

    $gateway->settle($first, 40_000, 1_000, 'provider_exact');
    expect($run->fresh()->status)->toBe('open')
        ->and($run->fresh()->held_micros)->toBe(60_000)
        ->and($run->fresh()->committed_micros)->toBe(40_000);

    $gateway->settle($second, 30_000, 750, 'provider_exact');
    $run->refresh();
    expect($run->orchestration_owned)->toBeTrue()
        ->and($run->status)->toBe('open')
        ->and($run->closed_at)->toBeNull()
        ->and($run->held_micros)->toBe(0)
        ->and($run->committed_micros)->toBe(70_000)
        ->and(AiUsageLedger::query()->count())->toBe(2);

    $closed = $gateway->closeRun($run);
    expect($closed->status)->toBe('closed')
        ->and($closed->closed_at)->not->toBeNull();
});

it('retains automatic close for existing single-operation callers', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    [$credential, $version] = aiBudgetRunCredential($user);
    $gateway = app(AiBudgetGateway::class);

    $operation = $gateway->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::Qualification,
        'completion',
        'openai/gpt-5-mini',
        2_000,
        80_000,
        'legacy-single-operation',
        $gateway->fingerprint(['operation' => 'legacy'], 'content'),
    );

    $gateway->settle($operation, 30_000, 750, 'provider_exact');
    $run = $operation->run()->firstOrFail();

    expect($run->orchestration_owned)->toBeFalse()
        ->and($run->status)->toBe('closed')
        ->and($run->closed_at)->not->toBeNull();
});

it('cancels an orchestration-owned run and releases unsettled operation holds', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    [$credential, $version] = aiBudgetRunCredential($user);
    $gateway = app(AiBudgetGateway::class);

    $run = $gateway->openRun($workspace, $user, AiTask::Qualification);
    $settled = $gateway->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::Qualification,
        'completion',
        'openai/gpt-5-mini',
        2_000,
        50_000,
        'cancel-operation-settled',
        $gateway->fingerprint(['operation' => 'settled'], 'content'),
        $run->id,
    );
    $pending = $gateway->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::Qualification,
        'completion',
        'openai/gpt-5-mini',
        2_000,
        60_000,
        'cancel-operation-pending',
        $gateway->fingerprint(['operation' => 'pending'], 'content'),
        $run->id,
    );
    $gateway->settle($settled, 20_000, 500, 'provider_exact');

    $cancelled = $gateway->cancelRun($run);
    $period = AiBudgetPeriod::query()->sole();
    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->held_micros)->toBe(0)
        ->and($cancelled->committed_micros)->toBe(20_000)
        ->and($pending->fresh()->status)->toBe(AiOperationStatus::Cancelled)
        ->and($settled->fresh()->status)->toBe(AiOperationStatus::Succeeded)
        ->and($period->held_micros)->toBe(0)
        ->and(AiUsageLedger::query()->count())->toBe(1);

    expect($gateway->cancelRun($cancelled)->status)->toBe('cancelled')
        ->and(AiProviderOperation::query()->where('status', AiOperationStatus::Cancelled)->count())->toBe(1);
});

it('refuses to cancel a run when an operation may have reached the provider', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    [$credential, $version] = aiBudgetRunCredential($user);
    $gateway = app(AiBudgetGateway::class);

    $run = $gateway->openRun($workspace, $user, AiTask::Qualification);
    $operation = $gateway->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::Qualification,
        'completion',
        'openai/gpt-5-mini',
        2_000,
        50_000,
        'cancel-operation-uncertain',
        $gateway->fingerprint(['operation' => 'uncertain'], 'content'),
        $run->id,
    );
    $operation->forceFill(['status' => AiOperationStatus::Executing])->save();

    expect(fn () => $gateway->cancelRun($run))
        ->toThrow(RuntimeException::class, 'may have reached the provider');

    expect($run->fresh()->status)->toBe('open')
        ->and($run->fresh()->held_micros)->toBe(50_000)
        ->and($operation->fresh()->status)->toBe(AiOperationStatus::Executing)
        ->and(AiBudgetPeriod::query()->sole()->held_micros)->toBe(50_000);
});
