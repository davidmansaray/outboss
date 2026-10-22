<?php

use App\Domain\Integrations\Connections\ConnectionManager;
use App\Domain\Integrations\Connections\RegistrationManager;
use App\Domain\Integrations\Contracts\CapabilityRequest;
use App\Domain\Integrations\Contracts\CapabilityResult;
use App\Domain\Integrations\DTOs\ExternalCostEstimate;
use App\Domain\Integrations\Enums\ExternalOperationAttemptKind;
use App\Domain\Integrations\Enums\ExternalOperationAttemptStatus;
use App\Domain\Integrations\Enums\IntegrationOperationStatus;
use App\Domain\Integrations\Exceptions\CapabilitySchemaViolation;
use App\Domain\Integrations\Operations\ExecuteExternalOperation;
use App\Domain\Integrations\Operations\IntegrationBudgetGateway;
use App\Domain\Integrations\Operations\ReconcileExternalOperations;
use App\Domain\Integrations\Providers\FakeCapabilityProvider;
use App\Domain\Integrations\Support\CapabilitySchemaValidator;
use App\Models\ExternalOperation;
use App\Models\ExternalOperationAttempt;
use App\Models\IntegrationBudgetPeriod;
use App\Models\IntegrationProviderRegistration;
use App\Models\User;

/** @return array{user: User, registration: IntegrationProviderRegistration} */
function createHardeningIntegration(): array
{
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $connection = app(ConnectionManager::class)->connect($workspace, 'fake', 'hardening-secret', $user);
    $registration = app(RegistrationManager::class)->register($workspace, $connection, 'account.discover', $user);
    app(RegistrationManager::class)->route($workspace, $registration, $user);

    return ['user' => $user, 'registration' => $registration];
}

it('reconciles an expired executing lease and releases its held budget', function () {
    FakeCapabilityProvider::reset();
    ['user' => $user, 'registration' => $registration] = createHardeningIntegration();
    $workspace = $user->currentWorkspace();
    $operation = app(IntegrationBudgetGateway::class)->reserve(
        workspace: $workspace,
        initiator: $user,
        provider: (string) $registration->provider,
        capability: (string) $registration->capability,
        operation: 'account_discover',
        endpoint: 'account.discover',
        estimate: new ExternalCostEstimate(1, 'requests', 1_000, 'test'),
        idempotencyKey: 'expired-executing',
        requestFingerprint: app(CapabilitySchemaValidator::class)->fingerprint(
            ['input' => ['query' => 'coffee']],
            'account.discover',
        ),
        resolvedRegistration: $registration,
    );
    $executeAttempt = ExternalOperationAttempt::query()->create([
        'operation_id' => $operation->id,
        'attempt_number' => 1,
        'kind' => ExternalOperationAttemptKind::Execute,
        'status' => ExternalOperationAttemptStatus::Started,
    ]);
    $operation->forceFill([
        'status' => IntegrationOperationStatus::Executing,
        'started_at' => now()->subMinutes(20),
        'lease_owner' => 'dead-worker',
        'lease_expires_at' => now()->subMinute(),
    ])->save();
    FakeCapabilityProvider::queue('account.discover', new CapabilityResult(
        state: 'complete',
        data: ['records' => [['id' => 'recovered']]],
        cost: new ExternalCostEstimate(1, 'requests', 1_000, 'reconciled'),
    ));

    expect(app(ReconcileExternalOperations::class)->handle($operation->id))->toBe(1)
        ->and($operation->fresh()->status)->toBe(IntegrationOperationStatus::Reconciled)
        ->and(IntegrationBudgetPeriod::query()->sole()->held_micros)->toBe(0)
        ->and(IntegrationBudgetPeriod::query()->sole()->committed_micros)->toBe(1_000)
        ->and($executeAttempt->fresh()->status)->toBe(ExternalOperationAttemptStatus::Ambiguous)
        ->and(ExternalOperationAttempt::query()->where('kind', ExternalOperationAttemptKind::Reconcile)->sole()->status)
        ->toBe(ExternalOperationAttemptStatus::Reconciled);
});

it('rejects replacement action payloads and mismatched operation references during reconciliation', function () {
    FakeCapabilityProvider::reset();
    ['user' => $user, 'registration' => $registration] = createHardeningIntegration();
    $workspace = $user->currentWorkspace();
    $operation = app(IntegrationBudgetGateway::class)->reserve(
        workspace: $workspace,
        initiator: $user,
        provider: (string) $registration->provider,
        capability: (string) $registration->capability,
        operation: 'account_discover',
        endpoint: 'account.discover',
        estimate: new ExternalCostEstimate(1, 'requests', 1_000, 'test'),
        idempotencyKey: 'guarded-reconciliation',
        requestFingerprint: app(CapabilitySchemaValidator::class)->fingerprint(
            ['input' => ['query' => 'coffee']],
            'account.discover',
        ),
        resolvedRegistration: $registration,
    );
    $operation->forceFill(['status' => IntegrationOperationStatus::Ambiguous])->save();

    $invalidRequests = [
        new CapabilityRequest('web.search', []),
        new CapabilityRequest('account.discover', [], providerKey: 'another-provider'),
        new CapabilityRequest('account.discover', [], sourceRunId: 'another-source-run'),
        new CapabilityRequest('account.discover', ['query' => 'replacement-action']),
        new CapabilityRequest('account.discover', [], policySnapshot: ['replacement' => true]),
        new CapabilityRequest('account.discover', [], policyVersion: 2),
    ];

    foreach ($invalidRequests as $request) {
        expect(fn () => app(ExecuteExternalOperation::class)->reconcile($workspace, $operation, $request))
            ->toThrow(RuntimeException::class, 'does not match the reserved operation');
    }

    expect(FakeCapabilityProvider::$calls)->toHaveCount(0)
        ->and($operation->fresh()->status)->toBe(IntegrationOperationStatus::Ambiguous);
});

it('pins reservation and execution to the routed registration and credential', function () {
    FakeCapabilityProvider::reset();
    ['user' => $user, 'registration' => $first] = createHardeningIntegration();
    $workspace = $user->currentWorkspace();
    $second = app(RegistrationManager::class)->register(
        $workspace,
        $first->connection()->firstOrFail(),
        'account.discover',
        $user,
        adapterVersion: 'fake-2',
    );
    app(RegistrationManager::class)->route($workspace, $second, $user);

    app(ExecuteExternalOperation::class)->execute(
        $workspace,
        new CapabilityRequest('account.discover', ['query' => 'coffee']),
        'registration-pin',
        $user,
    );
    $operation = ExternalOperation::query()->sole();

    expect($operation->registration_id)->toBe($second->id)
        ->and($operation->connection_id)->toBe($second->connection_id)
        ->and($operation->credential_version_id)->toBe($second->connection()->firstOrFail()->active_credential_version_id)
        ->and(FakeCapabilityProvider::$calls)->toHaveCount(1);
});

it('blocks a queued operation when its pinned credential is rotated before execution', function () {
    FakeCapabilityProvider::reset();
    ['user' => $user, 'registration' => $registration] = createHardeningIntegration();
    $workspace = $user->currentWorkspace();
    $gateway = app(IntegrationBudgetGateway::class);
    $operation = $gateway->reserve(
        workspace: $workspace,
        initiator: $user,
        provider: (string) $registration->provider,
        capability: (string) $registration->capability,
        operation: 'account_discover',
        endpoint: 'account.discover',
        estimate: new ExternalCostEstimate(1, 'requests', 1_000, 'test'),
        idempotencyKey: 'rotated-before-claim',
        requestFingerprint: app(CapabilitySchemaValidator::class)->fingerprint(
            ['input' => ['query' => 'coffee'], 'metadata' => [], 'policy' => []],
            'account.discover',
        ),
        resolvedRegistration: $registration,
    );
    app(ConnectionManager::class)->rotate($workspace, 'fake', 'new-hardening-secret', $user);

    $result = app(ExecuteExternalOperation::class)->execute(
        $workspace,
        new CapabilityRequest('account.discover', ['query' => 'coffee']),
        'rotated-before-claim',
        $user,
    );

    expect($result->state)->toBe('failed')
        ->and($operation->fresh()->status)->toBe(IntegrationOperationStatus::Failed)
        ->and(IntegrationBudgetPeriod::query()->sole()->held_micros)->toBe(0)
        ->and(FakeCapabilityProvider::$calls)->toHaveCount(0);
});

it('rejects oversized canonical request envelopes before reservation', function () {
    FakeCapabilityProvider::reset();
    ['user' => $user] = createHardeningIntegration();
    config()->set('integrations.max_request_bytes', 80);

    expect(fn () => app(ExecuteExternalOperation::class)->execute(
        $user->currentWorkspace(),
        new CapabilityRequest('account.discover', ['query' => 'coffee']),
        'oversized-request',
        $user,
    ))->toThrow(CapabilitySchemaViolation::class)
        ->and(ExternalOperation::query()->count())->toBe(0)
        ->and(FakeCapabilityProvider::$calls)->toHaveCount(0);
});

it('rejects oversized request metadata before provider activity', function () {
    FakeCapabilityProvider::reset();
    ['user' => $user] = createHardeningIntegration();
    config()->set('integrations.max_metadata_bytes', 12);

    expect(fn () => app(ExecuteExternalOperation::class)->execute(
        $user->currentWorkspace(),
        new CapabilityRequest(
            'account.discover',
            ['query' => 'coffee'],
            metadata: ['trace' => str_repeat('x', 40)],
        ),
        'oversized-metadata',
        $user,
    ))->toThrow(CapabilitySchemaViolation::class)
        ->and(ExternalOperation::query()->count())->toBe(0)
        ->and(FakeCapabilityProvider::$calls)->toHaveCount(0);
});

it('keeps an oversized provider result ambiguous instead of settling it', function () {
    FakeCapabilityProvider::reset();
    ['user' => $user] = createHardeningIntegration();
    config()->set('integrations.max_result_bytes', 120);
    FakeCapabilityProvider::queue('account.discover', new CapabilityResult(
        state: 'complete',
        data: ['records' => [['name' => str_repeat('x', 400)]]],
    ));

    $result = app(ExecuteExternalOperation::class)->execute(
        $user->currentWorkspace(),
        new CapabilityRequest('account.discover', ['query' => 'coffee']),
        'oversized-result',
        $user,
    );

    expect($result->state)->toBe('ambiguous')
        ->and(ExternalOperation::query()->sole()->status)->toBe(IntegrationOperationStatus::Ambiguous)
        ->and(IntegrationBudgetPeriod::query()->sole()->held_micros)->toBe(1_000)
        ->and(ExternalOperation::query()->sole()->actual_cost_micros)->toBeNull()
        ->and(ExternalOperation::query()->sole()->safe_error_code)->toBe('output_invalid');
});

it('rejects oversized provider metadata before settlement', function () {
    FakeCapabilityProvider::reset();
    ['user' => $user] = createHardeningIntegration();
    config()->set('integrations.max_metadata_bytes', 24);
    FakeCapabilityProvider::queue('account.discover', new CapabilityResult(
        state: 'complete',
        data: ['records' => [['id' => 'bounded']]],
        metadata: ['provider_note' => str_repeat('x', 100)],
    ));

    $result = app(ExecuteExternalOperation::class)->execute(
        $user->currentWorkspace(),
        new CapabilityRequest('account.discover', ['query' => 'coffee']),
        'oversized-result-metadata',
        $user,
    );

    expect($result->state)->toBe('ambiguous')
        ->and(ExternalOperation::query()->sole()->status)->toBe(IntegrationOperationStatus::Ambiguous)
        ->and(IntegrationBudgetPeriod::query()->sole()->held_micros)->toBe(1_000);
});
