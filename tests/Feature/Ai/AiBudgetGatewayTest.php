<?php

use App\Domain\Ai\Enums\AiCredentialStatus;
use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Exceptions\AiBudgetExceeded;
use App\Domain\Ai\Exceptions\AiIdempotencyConflict;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Ai\Services\AiCostEstimator;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Ai\Services\ExecuteProviderOperation;
use App\Models\AiBudgetPeriod;
use App\Models\AiProviderCallAttempt;
use App\Models\AiProviderCredential;
use App\Models\AiProviderOperation;
use App\Models\AiTaskSetting;
use App\Models\AiUsageLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function aiCredential(User $user, AiProviderName $provider = AiProviderName::OpenRouter): array
{
    $workspace = $user->currentWorkspace();
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
        'encrypted_secret' => 'test-secret-value',
        'status' => 'active',
        'created_by_user_id' => $user->id,
        'tested_at' => now(),
        'activated_at' => now(),
    ]);
    $credential->forceFill(['active_version_id' => $version->id])->save();

    return [$credential->fresh(), $version];
}

it('atomically reserves worst-case cost and replaces the hold with settled usage', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    [$credential, $version] = aiCredential($user);
    $gateway = app(AiBudgetGateway::class);
    $fingerprint = $gateway->fingerprint(['task' => 'qualification'], 'private content');

    $operation = $gateway->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::Qualification,
        'completion',
        'openai/gpt-5-mini',
        2_500,
        100_000,
        'run-1-openrouter',
        $fingerprint,
    );

    $period = AiBudgetPeriod::query()->sole();
    expect($operation->status)->toBe(AiOperationStatus::Reserved)
        ->and($period->held_micros)->toBe(100_000)
        ->and($period->committed_micros)->toBe(0);

    $settled = $gateway->settle($operation, 42_000, 1_125, 'provider_exact', [
        'native_unit' => 'tokens',
        'input_tokens' => 1_000,
        'output_tokens' => 125,
        'provider_generation_id' => 'gen-safe-1',
    ]);

    $period->refresh();
    expect($settled->status)->toBe(AiOperationStatus::Succeeded)
        ->and($period->held_micros)->toBe(0)
        ->and($period->committed_micros)->toBe(42_000)
        ->and(AiUsageLedger::query()->sole()->cost_micros)->toBe(42_000);
});

it('keeps uncertain cost reserved and blocks a concurrent operation that would overspend', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $settings = app(AiWorkspaceSettings::class);
    $settings->ensureDefaults($workspace);
    $settings->updateBudget($workspace, $user, 150_000, 8_000);
    [$credential, $version] = aiCredential($user);
    $gateway = app(AiBudgetGateway::class);

    $first = $gateway->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::Qualification,
        'completion',
        'openai/gpt-5-mini',
        2_000,
        100_000,
        'uncertain-1',
        $gateway->fingerprint(['request' => 1], 'content-1'),
    );
    $gateway->markUncertain($first, 'provider_unavailable', 'The provider did not return a reliable result.');

    expect(fn () => $gateway->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::Qualification,
        'completion',
        'openai/gpt-5-mini',
        2_000,
        60_000,
        'concurrent-2',
        $gateway->fingerprint(['request' => 2], 'content-2'),
    ))->toThrow(AiBudgetExceeded::class);

    expect(AiBudgetPeriod::query()->sole()->held_micros)->toBe(100_000);
});

it('allows a lower limit below committed and held spend without cancelling the reservation', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $settings = app(AiWorkspaceSettings::class);
    $settings->ensureDefaults($workspace);
    [$credential, $version] = aiCredential($user);
    $gateway = app(AiBudgetGateway::class);

    $operation = $gateway->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::Qualification,
        'completion',
        'openai/gpt-5-mini',
        1_000,
        100_000,
        'lower-limit',
        $gateway->fingerprint(['task' => 'qualification'], 'content'),
    );

    $settings->updateBudget($workspace, $user, 50_000, 8_000);

    expect(AiBudgetPeriod::query()->sole()->over_limit)->toBeTrue()
        ->and($operation->fresh()->status)->toBe(AiOperationStatus::Reserved)
        ->and($operation->fresh()->reserved_cost_micros)->toBe(100_000);
});

it('rebuilds a drifted projection from ledger charges and open reservations', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    [$credential, $version] = aiCredential($user);
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
        100_000,
        'projection-settled',
        $gateway->fingerprint(['request' => 'settled'], 'content'),
    );
    $gateway->settle($operation, 40_000, 1_000, 'provider_exact');
    $gateway->reserve(
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::Qualification,
        'completion',
        'openai/gpt-5-mini',
        2_000,
        75_000,
        'projection-held',
        $gateway->fingerprint(['request' => 'held'], 'content'),
    );

    AiBudgetPeriod::query()->sole()->forceFill([
        'held_micros' => 1,
        'committed_micros' => 2,
    ])->save();

    $this->artisan('ai:rebuild-budget-projections', ['--workspace' => $workspace->id])
        ->expectsOutputToContain('Rebuilt 1 AI budget period projection(s).')
        ->assertSuccessful();

    expect(AiBudgetPeriod::query()->sole()->held_micros)->toBe(75_000)
        ->and(AiBudgetPeriod::query()->sole()->committed_micros)->toBe(40_000);
});

it('makes matching idempotency reuse a no-op and rejects a changed fingerprint', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    app(AiWorkspaceSettings::class)->ensureDefaults($workspace);
    [$credential, $version] = aiCredential($user);
    $gateway = app(AiBudgetGateway::class);
    $fingerprint = $gateway->fingerprint(['task' => 'qualification'], 'same content');
    $arguments = [
        $workspace,
        $credential,
        $version,
        $user,
        AiTask::Qualification,
        'completion',
        'openai/gpt-5-mini',
        2_000,
        100_000,
        'idempotent-operation',
    ];

    $first = $gateway->reserve(...[...$arguments, $fingerprint]);
    $second = $gateway->reserve(...[...$arguments, $fingerprint]);

    expect($second->id)->toBe($first->id)
        ->and(AiBudgetPeriod::query()->sole()->held_micros)->toBe(100_000);

    expect(fn () => $gateway->reserve(...[
        ...$arguments,
        $gateway->fingerprint(['task' => 'qualification'], 'changed content'),
    ]))->toThrow(AiIdempotencyConflict::class);
});

it('admits two drafting arms atomically before either can reach the provider', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $settings = app(AiWorkspaceSettings::class);
    $settings->ensureDefaults($workspace);
    aiCredential($user);
    $task = AiTaskSetting::query()->where('workspace_id', $workspace->id)
        ->where('task', AiTask::MessageDrafting->value)->firstOrFail();
    $content = '{"bounded":"two-arm-drafting-input"}';
    $estimate = app(AiCostEstimator::class)->openRouter(
        (string) $task->model_id,
        strlen($content),
        (int) config('ai.tasks.message_drafting.maximum_output_tokens'),
    );
    $settings->updateBudget($workspace, $user, $estimate->costMicros + 1, 8_000);
    $gateway = app(AiBudgetGateway::class);
    $envelope = $gateway->openEnvelope(
        $workspace,
        $user,
        'objective_run',
        'atomic-two-arm-capacity',
        $estimate->costMicros * 2,
    );
    $control = $gateway->openRun($workspace, $user, AiTask::MessageDrafting, $envelope);
    $treatment = $gateway->openRun($workspace, $user, AiTask::MessageDrafting, $envelope);

    expect($gateway->mayAdmitBatch(
        $workspace,
        AiTask::MessageDrafting,
        [$estimate->costMicros, $estimate->costMicros],
        (int) $envelope->hard_limit_micros,
    ))->toBeFalse();

    $executor = app(ExecuteProviderOperation::class);
    expect(fn () => DB::transaction(function () use ($executor, $workspace, $user, $content, $control, $treatment): void {
        $executor->reserveGeneration($workspace, $user, AiTask::MessageDrafting, $content, 'atomic-control', (string) $control->id);
        $executor->reserveGeneration($workspace, $user, AiTask::MessageDrafting, $content, 'atomic-treatment', (string) $treatment->id);
    }, 3))->toThrow(AiBudgetExceeded::class)
        ->and(AiProviderOperation::query()->count())->toBe(0)
        ->and(AiProviderCallAttempt::query()->count())->toBe(0)
        ->and(AiUsageLedger::query()->count())->toBe(0)
        ->and((int) AiBudgetPeriod::query()->sum('held_micros'))->toBe(0)
        ->and((int) $envelope->fresh()->held_micros)->toBe(0)
        ->and((int) $control->fresh()->held_micros)->toBe(0)
        ->and((int) $treatment->fresh()->held_micros)->toBe(0);
});
