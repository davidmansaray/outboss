<?php

use App\Domain\Integrations\Connections\ConnectionManager;
use App\Domain\Integrations\Connections\RegistrationManager;
use App\Domain\Integrations\Contracts\CapabilityRequest;
use App\Domain\Integrations\Contracts\CapabilityResult;
use App\Domain\Integrations\DTOs\SafeExternalError;
use App\Domain\Integrations\Enums\IntegrationOperationStatus;
use App\Domain\Integrations\Exceptions\CapabilitySchemaViolation;
use App\Domain\Integrations\Exceptions\CapabilityUnavailable;
use App\Domain\Integrations\Exceptions\ExternalBudgetExceeded;
use App\Domain\Integrations\Exceptions\ExternalIdempotencyConflict;
use App\Domain\Integrations\Operations\ExecuteExternalOperation;
use App\Domain\Integrations\Operations\IntegrationBudgetGateway;
use App\Domain\Integrations\Operations\ReconcileExternalOperations;
use App\Domain\Integrations\Providers\BrightData\FakeProvider as FakeBrightDataProvider;
use App\Domain\Integrations\Providers\FakeCapabilityProvider;
use App\Domain\Integrations\Providers\LocalBusiness\FakeProvider as FakeLocalBusinessProvider;
use App\Domain\Integrations\Support\CapabilityCatalogue;
use App\Domain\Integrations\Support\CapabilitySchemaValidator;
use App\Models\ExternalOperation;
use App\Models\ExternalUsageEntry;
use App\Models\IntegrationBudgetPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function configureFakeCapability(User $user, string $capability = 'account.discover'): void
{
    $workspace = $user->currentWorkspace();
    $connection = app(ConnectionManager::class)->connect($workspace, 'fake', 'fake-secret', $user);
    $registration = app(RegistrationManager::class)->register($workspace, $connection, $capability, $user);
    app(RegistrationManager::class)->route($workspace, $registration, $user);
}

it('catalogues stable capability contracts and keeps disabled providers unavailable', function () {
    $catalogue = app(CapabilityCatalogue::class);

    expect($catalogue->names())
        ->toContain('account.discover')
        ->toContain('web.search')
        ->toContain('conversion.ingest')
        ->toContain('ai.synthesize');
    expect(config('integrations.providers.openrouter.capabilities'))
        ->toContain('ai.synthesize')
        ->not->toContain('web.search');
    expect(config('integrations.providers.tavily.capabilities'))
        ->toContain('web.search');
    expect(fn () => app(ConnectionManager::class)->connect(
        User::factory()->create()->currentWorkspace(),
        'bright_data',
        'secret',
    ))->toThrow(CapabilityUnavailable::class);
});

it('rejects unsupported fields and fingerprints equivalent request key order identically', function () {
    $validator = app(CapabilitySchemaValidator::class);

    expect($validator->fingerprint(['b' => 2, 'a' => 1], 'web.search'))
        ->toBe($validator->fingerprint(['a' => 1, 'b' => 2], 'web.search'));

    expect(fn () => $validator->validateRequest('web.search', [
        'query' => 'site:example.test',
        'unexpected' => 'not allowed',
    ]))->toThrow(CapabilitySchemaViolation::class);
});

it('rotates encrypted credentials with a bounded retiring version', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $manager = app(ConnectionManager::class);

    $first = $manager->connect($workspace, 'fake', 'first-secret', $user);
    $second = $manager->rotate($workspace, 'fake', 'second-secret', $user);
    $firstVersion = $first->credentialVersions()->where('version', 1)->firstOrFail();
    $secondVersion = $second->activeCredentialVersion()->firstOrFail();

    expect($second->status->value)->toBe('healthy')
        ->and($secondVersion->status->value)->toBe('active')
        ->and($firstVersion->fresh()->status->value)->toBe('retiring')
        ->and($firstVersion->fresh()->retire_after)->not->toBeNull()
        ->and(DB::table('integration_credential_versions')->where('id', $secondVersion->id)->value('encrypted_secret'))
        ->not->toBe('second-secret');
});

it('reserves, settles, and replays a neutral operation without a second provider call', function () {
    FakeCapabilityProvider::reset();
    $user = User::factory()->create();
    configureFakeCapability($user);
    $workspace = $user->currentWorkspace();
    $service = app(ExecuteExternalOperation::class);
    $request = new CapabilityRequest('account.discover', ['query' => 'coffee', 'limit' => 1]);

    $first = $service->execute($workspace, $request, 'same-key', $user);
    $second = $service->execute($workspace, $request, 'same-key', $user);

    expect($first->state)->toBe('complete')
        ->and($first->data['records'])->toHaveCount(1)
        ->and($second->metadata['idempotent_replay'])->toBeTrue()
        ->and(FakeCapabilityProvider::$calls)->toHaveCount(1)
        ->and(ExternalOperation::query()->count())->toBe(1)
        ->and(ExternalOperation::query()->sole()->status)->toBe(IntegrationOperationStatus::Succeeded)
        ->and(IntegrationBudgetPeriod::query()->sole()->held_micros)->toBe(0)
        ->and(ExternalUsageEntry::query()->sole()->cost_micros)->toBe(1_000);
});

it('rejects an idempotency key reused for a different request before a provider call', function () {
    FakeCapabilityProvider::reset();
    $user = User::factory()->create();
    configureFakeCapability($user);
    $workspace = $user->currentWorkspace();
    $service = app(ExecuteExternalOperation::class);
    $service->execute($workspace, new CapabilityRequest('account.discover', ['query' => 'coffee']), 'same-key', $user);

    expect(fn () => $service->execute(
        $workspace,
        new CapabilityRequest('account.discover', ['query' => 'tea']),
        'same-key',
        $user,
    ))->toThrow(ExternalIdempotencyConflict::class)
        ->and(FakeCapabilityProvider::$calls)->toHaveCount(1);
});

it('keeps a provider-reaching ambiguous operation held until reconciliation', function () {
    FakeCapabilityProvider::reset();
    FakeCapabilityProvider::$nextError = new SafeExternalError(
        'provider_timeout',
        'The provider did not return a reliable result.',
        true,
    );
    $user = User::factory()->create();
    configureFakeCapability($user);
    $workspace = $user->currentWorkspace();
    $result = app(ExecuteExternalOperation::class)->execute(
        $workspace,
        new CapabilityRequest('account.discover', ['query' => 'coffee']),
        'ambiguous-key',
        $user,
    );

    expect($result->state)->toBe('ambiguous')
        ->and(ExternalOperation::query()->sole()->status)->toBe(IntegrationOperationStatus::Ambiguous)
        ->and(IntegrationBudgetPeriod::query()->sole()->held_micros)->toBe(1_000);
});

it('reconciles a due ambiguous operation and settles its held budget', function () {
    FakeCapabilityProvider::reset();
    FakeCapabilityProvider::$nextError = new SafeExternalError(
        'provider_timeout',
        'The provider did not return a reliable result.',
        true,
    );
    $user = User::factory()->create();
    configureFakeCapability($user);
    $workspace = $user->currentWorkspace();
    app(ExecuteExternalOperation::class)->execute(
        $workspace,
        new CapabilityRequest('account.discover', ['query' => 'coffee']),
        'reconcile-key',
        $user,
    );
    $operation = ExternalOperation::query()->sole();
    $operation->forceFill([
        'reconcile_after' => now()->subMinute(),
        'finalize_after' => now()->addHour(),
    ])->save();
    FakeCapabilityProvider::queue('account.discover', new CapabilityResult(
        'complete',
        ['records' => [['id' => 'reconciled-record', 'name' => 'Reconciled Coffee']]],
    ));

    expect(app(ReconcileExternalOperations::class)->handle($operation->id))->toBe(1)
        ->and($operation->fresh()->status)->toBe(IntegrationOperationStatus::Reconciled)
        ->and(IntegrationBudgetPeriod::query()->sole()->held_micros)->toBe(0)
        ->and(IntegrationBudgetPeriod::query()->sole()->committed_micros)->toBe(1_000)
        ->and(ExternalUsageEntry::query()->sole()->cost_basis)->toBe('reconciled_upper_bound');
});

it('conservatively finalizes an overdue ambiguous operation at its reserved upper bound', function () {
    FakeCapabilityProvider::reset();
    FakeCapabilityProvider::$nextError = new SafeExternalError(
        'provider_timeout',
        'The provider did not return a reliable result.',
        true,
    );
    $user = User::factory()->create();
    configureFakeCapability($user);
    $workspace = $user->currentWorkspace();
    app(ExecuteExternalOperation::class)->execute(
        $workspace,
        new CapabilityRequest('account.discover', ['query' => 'coffee']),
        'finalize-key',
        $user,
    );
    $operation = ExternalOperation::query()->sole();
    $operation->forceFill([
        'reconcile_after' => now()->addHour(),
        'finalize_after' => now()->subMinute(),
    ])->save();

    expect(app(ReconcileExternalOperations::class)->handle($operation->id))->toBe(1)
        ->and($operation->fresh()->status)->toBe(IntegrationOperationStatus::ManualReview)
        ->and($operation->fresh()->actual_cost_micros)->toBe(1_000)
        ->and($operation->fresh()->cost_basis)->toBe('upper_bound_estimate')
        ->and(IntegrationBudgetPeriod::query()->sole()->held_micros)->toBe(0)
        ->and(IntegrationBudgetPeriod::query()->sole()->committed_micros)->toBe(1_000)
        ->and(ExternalUsageEntry::query()->sole()->cost_basis)->toBe('upper_bound_estimate');
});

it('models local-business and Bright Data candidate states with deterministic fakes', function () {
    FakeLocalBusinessProvider::reset();
    $local = new FakeLocalBusinessProvider;
    $localResult = $local->execute(new CapabilityRequest('account.discover', ['query' => 'coffee']));
    expect($localResult->state)->toBe('complete')
        ->and($localResult->data['records'][0]['place_id'])->toBe('ChIJfake001');

    FakeBrightDataProvider::reset();
    FakeBrightDataProvider::$mode = 'accepted_202';
    $bright = new FakeBrightDataProvider;
    $brightResult = $bright->execute(new CapabilityRequest('account.discover', ['query' => 'founders']));
    expect($brightResult->state)->toBe('awaiting')
        ->and($brightResult->providerSnapshotId)->toBe('bd-snapshot-001');
});

it('enforces the monthly integration budget under reservation', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    configureFakeCapability($user);
    app(IntegrationBudgetGateway::class)->ensureDefaults($workspace)->forceFill([
        'monthly_limit_micros' => 1_000,
    ])->save();

    $service = app(ExecuteExternalOperation::class);
    $service->execute($workspace, new CapabilityRequest('account.discover', ['query' => 'coffee']), 'budget-1', $user);

    expect(fn () => $service->execute(
        $workspace,
        new CapabilityRequest('account.discover', ['query' => 'tea']),
        'budget-2',
        $user,
    ))->toThrow(ExternalBudgetExceeded::class);
});
