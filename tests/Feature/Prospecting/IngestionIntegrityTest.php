<?php

use App\Domain\People\Services\AccountEditor;
use App\Domain\People\Services\PersonEditor;
use App\Domain\Prospecting\DTOs\IngestionBatch;
use App\Domain\Prospecting\DTOs\IngestionObservation;
use App\Domain\Prospecting\Enums\ObservationDisposition;
use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Exceptions\SourceIdempotencyConflict;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Domain\Prospecting\Services\ProspectIngestionService;
use App\Models\Account;
use App\Models\AccountSourceMembership;
use App\Models\ExternalIdentity;
use App\Models\Person;
use App\Models\PersonSourceMembership;
use App\Models\ProspectSource;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps web, REST, and MCP editor creates on distinct system sources', function (): void {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();

    app(AccountEditor::class)->create(
        workspace: $workspace,
        actor: $user,
        data: ['name' => 'Web Co', 'website' => 'https://web.example'],
        idempotencyKey: 'web-account',
        sourceKey: 'manual',
    );
    app(AccountEditor::class)->create(
        workspace: $workspace,
        actor: $user,
        data: ['name' => 'REST Co', 'website' => 'https://rest.example'],
        idempotencyKey: 'rest-account',
        sourceKey: 'rest_api',
    );
    app(PersonEditor::class)->create(
        workspace: $workspace,
        actor: $user,
        data: ['email' => 'mcp@example.test', 'full_name' => 'MCP Person'],
        idempotencyKey: 'mcp-person',
        sourceKey: 'mcp',
    );

    $sources = ProspectSource::query()
        ->where('workspace_id', $workspace->id)
        ->whereIn('system_key', ['manual', 'rest_api', 'mcp'])
        ->get()
        ->keyBy('system_key');

    expect($sources)->toHaveCount(3)
        ->and($sources['manual']->currentVersion->configuration['ingress'])->toBe('web')
        ->and($sources['rest_api']->currentVersion->configuration['ingress'])->toBe('rest')
        ->and($sources['mcp']->currentVersion->configuration['ingress'])->toBe('mcp')
        ->and($sources['manual']->currentVersion->origin->value)->toBe('system')
        ->and($sources['rest_api']->currentVersion->origin->value)->toBe('system')
        ->and($sources['mcp']->currentVersion->origin->value)->toBe('system')
        ->and($sources['manual']->runs()->latest()->firstOrFail()->initiator_type)->toBe('user')
        ->and($sources['rest_api']->runs()->latest()->firstOrFail()->initiator_type)->toBe('api')
        ->and($sources['mcp']->runs()->latest()->firstOrFail()->initiator_type)->toBe('mcp');
});

it('rejects repeated observation keys before creating a source run', function (): void {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $source = app(CreateProspectSource::class)->handle(
        workspace: $workspace,
        kind: ProspectSourceKind::Manual,
        displayName: 'Duplicate key source',
        actor: $user,
    );

    expect(fn (): mixed => app(ProspectIngestionService::class)->handle(new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'duplicate-observation-key',
        observations: [
            new IngestionObservation(
                entityType: 'account',
                observedValues: ['name' => 'One', 'website' => 'https://one.example'],
                observationKey: 'same-row',
            ),
            new IngestionObservation(
                entityType: 'account',
                observedValues: ['name' => 'One', 'website' => 'https://one.example'],
                observationKey: 'same-row',
            ),
        ],
        actor: $user,
    )))->toThrow(SourceIdempotencyConflict::class, 'The batch contains duplicate observation keys.');

    expect(SourceRun::query()->where('idempotency_key', 'duplicate-observation-key')->exists())->toBeFalse()
        ->and(SourceObservation::query()->where('prospect_source_id', $source->id)->exists())->toBeFalse();
});

it('classifies an account external identity collision before membership creation', function (): void {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $source = app(CreateProspectSource::class)->handle(
        workspace: $workspace,
        kind: ProspectSourceKind::Manual,
        displayName: 'External identity source',
        actor: $user,
    );
    $ingestion = app(ProspectIngestionService::class);

    $first = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'external-account-a',
        observations: [new IngestionObservation(
            entityType: 'account',
            observedValues: ['name' => 'Alpha', 'website' => 'https://alpha.example'],
            providerRecordId: 'provider-account-1',
            observationKey: 'provider-account-a',
        )],
        actor: $user,
    ));
    $alpha = $first->accounts[0];
    $beta = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Beta',
        'website' => 'https://beta.example',
        'domain_normalized' => 'beta.example',
    ]);

    $collision = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'external-account-b',
        observations: [new IngestionObservation(
            entityType: 'account',
            observedValues: ['name' => 'Beta', 'website' => 'https://beta.example'],
            providerRecordId: 'provider-account-1',
            observationKey: 'provider-account-b',
        )],
        actor: $user,
    ));
    $observation = $collision->observations[0];

    expect($observation->disposition)->toBe(ObservationDisposition::Conflicted)
        ->and($observation->safe_error_code)->toBe('external_identity_conflict')
        ->and($observation->account_id)->toBeNull()
        ->and(AccountSourceMembership::query()->where('account_id', $beta->id)->exists())->toBeFalse()
        ->and(AccountSourceMembership::query()->where('account_id', $alpha->id)->count())->toBe(1)
        ->and(ExternalIdentity::query()->where('value_hash', hash('sha256', 'provider-account-1'))->value('account_id'))->toBe($alpha->id)
        ->and($collision->run->conflicted)->toBe(1);
});

it('classifies a person external identity collision before membership creation', function (): void {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $source = app(CreateProspectSource::class)->handle(
        workspace: $workspace,
        kind: ProspectSourceKind::Manual,
        displayName: 'External person identity source',
        actor: $user,
    );
    $ingestion = app(ProspectIngestionService::class);

    $first = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'external-person-a',
        observations: [new IngestionObservation(
            entityType: 'person',
            observedValues: ['email' => 'alpha@example.test', 'full_name' => 'Alpha'],
            providerRecordId: 'provider-person-1',
            observationKey: 'provider-person-a',
        )],
        actor: $user,
    ));
    $alpha = $first->people[0];
    $beta = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'email_address' => 'beta@example.test',
        'email_normalized' => 'beta@example.test',
        'full_name' => 'Beta',
    ]);

    $collision = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'external-person-b',
        observations: [new IngestionObservation(
            entityType: 'person',
            observedValues: ['email' => 'beta@example.test', 'full_name' => 'Beta'],
            providerRecordId: 'provider-person-1',
            observationKey: 'provider-person-b',
        )],
        actor: $user,
    ));
    $observation = $collision->observations[0];

    expect($observation->disposition)->toBe(ObservationDisposition::Conflicted)
        ->and($observation->safe_error_code)->toBe('external_identity_conflict')
        ->and($observation->person_id)->toBeNull()
        ->and(PersonSourceMembership::query()->where('person_id', $beta->id)->exists())->toBeFalse()
        ->and(PersonSourceMembership::query()->where('person_id', $alpha->id)->count())->toBe(1)
        ->and(ExternalIdentity::query()->where('value_hash', hash('sha256', 'provider-person-1'))->value('person_id'))->toBe($alpha->id)
        ->and($collision->run->conflicted)->toBe(1);
});
