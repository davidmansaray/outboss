<?php

use App\Domain\People\Services\AccountEditor;
use App\Domain\People\Services\PersonEditor;
use App\Domain\Prospecting\DTOs\IngestionBatch;
use App\Domain\Prospecting\DTOs\IngestionObservation;
use App\Domain\Prospecting\Enums\MembershipState;
use App\Domain\Prospecting\Enums\ObservationDisposition;
use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Enums\ProspectSourceStatus;
use App\Domain\Prospecting\Enums\SourceRunState;
use App\Domain\Prospecting\Exceptions\SourceIdempotencyConflict;
use App\Domain\Prospecting\Exceptions\SourceRunConflict;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Domain\Prospecting\Services\DeleteProspectSourceData;
use App\Domain\Prospecting\Services\ExpireSourceObservations;
use App\Domain\Prospecting\Services\ProspectIngestionService;
use App\Domain\Prospecting\Services\StartSourceRun;
use App\Domain\Prospecting\Services\UpdateProspectSource;
use App\Models\Account;
use App\Models\AccountSourceMembership;
use App\Models\CanonicalFieldProvenance;
use App\Models\ExternalIdentity;
use App\Models\Person;
use App\Models\PersonSourceMembership;
use App\Models\ProspectSource;
use App\Models\ProspectSourceVersion;
use App\Models\SourceObservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates an immutable source version and appends versions on configuration updates', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $create = app(CreateProspectSource::class);

    $source = $create->handle(
        workspace: $workspace,
        kind: ProspectSourceKind::Manual,
        displayName: 'Manual prospects',
        configuration: ['ingress' => 'web', 'system_managed' => false],
        actor: $user,
    );
    $firstVersion = $source->currentVersion()->firstOrFail();

    expect($firstVersion->version)->toBe(1)
        ->and($firstVersion->configuration_hash)->toHaveLength(64)
        ->and($source->status)->toBe(ProspectSourceStatus::Active);

    expect(fn () => $firstVersion->forceFill(['configuration_hash' => str_repeat('0', 64)])->save())
        ->toThrow(LogicException::class);

    $updated = app(UpdateProspectSource::class)->handle(
        source: $source,
        configuration: ['ingress' => 'web', 'system_managed' => true],
        actor: $user,
    );

    expect($updated->currentVersion()->firstOrFail()->version)->toBe(2)
        ->and($source->versions()->count())->toBe(2)
        ->and($firstVersion->fresh()->configuration['system_managed'])->toBeFalse();
});

it('creates a csv source with its default empty mapping', function () {
    $user = User::factory()->create();

    $source = app(CreateProspectSource::class)->handle(
        workspace: $user->currentWorkspace(),
        kind: ProspectSourceKind::CsvImport,
        displayName: 'Unconfigured CSV import',
        actor: $user,
    );

    expect($source->currentVersion()->firstOrFail()->configuration)->toMatchArray([
        'headers' => [],
        'mapping' => [],
        'import_motion' => 'people',
    ]);
});

it('rejects provider source kinds until their selection gates are enabled', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();

    foreach ([ProspectSourceKind::Provider, ProspectSourceKind::LocalBusiness] as $kind) {
        expect(fn () => app(CreateProspectSource::class)->handle(
            $workspace,
            $kind,
            'Disabled '.$kind->value,
            actor: $user,
        ))->toThrow(ValidationException::class, 'This prospect source kind is not enabled in S1.');
    }

    $source = ProspectSource::factory()->create([
        'workspace_id' => $workspace->id,
        'kind' => ProspectSourceKind::Provider,
    ]);
    $version = ProspectSourceVersion::factory()->forSource($source)->create();
    $source->forceFill(['current_version_id' => $version->id])->save();

    expect(fn () => app(StartSourceRun::class)->handle(
        $workspace,
        $source,
        $version,
        'disabled-provider-run',
        actor: $user,
    ))->toThrow(SourceRunConflict::class, 'This prospect source kind is not enabled in S1.');
});

it('resolves deterministic identities, preserves provenance, and replays an idempotent batch', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $source = app(CreateProspectSource::class)->handle(
        $workspace,
        ProspectSourceKind::Manual,
        'Manual source',
        actor: $user,
    );
    $ingestion = app(ProspectIngestionService::class);

    $accountBatch = new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'core-account-1',
        observations: [new IngestionObservation(
            entityType: 'account',
            observedValues: [
                'name' => 'Acme',
                'website' => 'https://acme.example/about',
                'tags' => ['saas'],
            ],
            observationKey: 'account-row-1',
        )],
        actor: $user,
        initiatorType: 'user',
    );
    $accountResult = $ingestion->handle($accountBatch);
    $account = $accountResult->accounts[0];

    $personResult = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'core-person-1',
        observations: [new IngestionObservation(
            entityType: 'person',
            observedValues: [
                'email' => 'Maya@Acme.example',
                'full_name' => 'Maya Chen',
                'account_id' => $account->id,
            ],
            observationKey: 'person-row-1',
        )],
        actor: $user,
        initiatorType: 'user',
    ));
    $person = $personResult->people[0];

    $replayed = $ingestion->handle($accountBatch);
    expect($replayed->replayed)->toBeTrue()
        ->and($replayed->accounts[0]->id)->toBe($account->id)
        ->and(Account::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and(SourceObservation::query()->where('source_run_id', $accountResult->run->id)->count())->toBe(1)
        ->and($person->email_normalized)->toBe('maya@acme.example')
        ->and($person->account_id)->toBe($account->id)
        ->and(AccountSourceMembership::query()->where('account_id', $account->id)->count())->toBe(1)
        ->and(PersonSourceMembership::query()->where('person_id', $person->id)->count())->toBe(1)
        ->and(CanonicalFieldProvenance::query()->where('account_id', $account->id)->exists())->toBeTrue()
        ->and(CanonicalFieldProvenance::query()->where('person_id', $person->id)->exists())->toBeTrue()
        ->and($personResult->run->state)->toBe(SourceRunState::Completed);

    expect(fn () => $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'core-account-1',
        observations: [new IngestionObservation(
            entityType: 'account',
            observedValues: ['name' => 'Different', 'website' => 'https://different.example'],
            observationKey: 'account-row-1',
        )],
        actor: $user,
        initiatorType: 'user',
    )))->toThrow(SourceIdempotencyConflict::class);

    $secondSource = app(CreateProspectSource::class)->handle(
        $workspace,
        ProspectSourceKind::ApiImport,
        'Second source',
        configuration: ['ingress' => 'rest'],
        actor: $user,
    );
    $linked = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $secondSource,
        sourceVersion: null,
        idempotencyKey: 'core-account-2',
        observations: [new IngestionObservation(
            entityType: 'account',
            observedValues: ['name' => 'Acme updated', 'website' => 'https://acme.example', 'tags' => ['enterprise']],
            observationKey: 'second-source-account',
        )],
        actor: $user,
        initiatorType: 'api',
    ));

    expect($linked->accounts[0]->id)->toBe($account->id)
        ->and($account->fresh()->tags)->toContain('saas', 'enterprise')
        ->and(AccountSourceMembership::query()->where('account_id', $account->id)->count())->toBe(2);
});

it('keeps suppressed people observable and non-contactable without throwing away the canonical row', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $source = app(CreateProspectSource::class)->ensureSystemSource($workspace, 'suppressed-test', $user);
    $suppression = $workspace->suppressions()->create([
        'user_id' => $user->id,
        'email_address' => 'blocked@example.test',
        'email_normalized' => 'blocked@example.test',
        'reason' => 'manual',
    ]);

    $result = app(ProspectIngestionService::class)->handle(new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'suppressed-person-1',
        observations: [new IngestionObservation(
            entityType: 'person',
            observedValues: ['email' => $suppression->email_address, 'full_name' => 'Blocked Person'],
            observationKey: 'suppressed-row-1',
        )],
        actor: $user,
        initiatorType: 'system',
    ));
    $person = $result->people[0];
    $observation = $result->observations[0];

    expect($person->email_normalized)->toBe('blocked@example.test')
        ->and($observation->disposition)->toBe(ObservationDisposition::Suppressed)
        ->and($observation->person_id)->toBe($person->id)
        ->and($observation->safe_error_message)->toContain('no contactable create')
        ->and(PersonSourceMembership::query()->where('person_id', $person->id)->firstOrFail()->state)
        ->toBe(MembershipState::Suppressed);
});

it('rebuilds memberships after mixed-retention expiry and allows editor deletes with lineage present', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $source = app(CreateProspectSource::class)->ensureSystemSource($workspace, 'retention-test', $user);
    $ingestion = app(ProspectIngestionService::class);

    $first = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'retention-first',
        observations: [new IngestionObservation('account', ['name' => 'Retain Co', 'website' => 'https://retain.example'], observationKey: 'retention-first-row')],
    ));
    $account = $first->accounts[0];
    $second = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'retention-second',
        observations: [new IngestionObservation('account', ['name' => 'Retain Co', 'website' => 'https://retain.example'], observationKey: 'retention-second-row')],
    ));
    $firstObservation = $first->observations[0];
    $secondObservation = $second->observations[0];
    $firstObservation->forceFill(['retain_until' => Carbon::now()->subMinute()])->save();
    $secondObservation->forceFill(['retain_until' => Carbon::now()->addDay()])->save();

    expect(app(ExpireSourceObservations::class)->handle($workspace, $source))->toBe(1)
        ->and($account->sourceMemberships()->firstOrFail()->fresh()->last_source_observation_id)->toBe($secondObservation->id)
        ->and($account->sourceMemberships()->firstOrFail()->state)->toBe(MembershipState::Active);

    $secondObservation->forceFill(['retain_until' => Carbon::now()->subMinute()])->save();
    expect(app(ExpireSourceObservations::class)->handle($workspace, $source))->toBe(1)
        ->and($account->sourceMemberships()->firstOrFail()->fresh()->state)->toBe(MembershipState::Expired);

    // The editor contract deletes the canonical record while preserving its
    // source observations. Composite membership FKs must cascade cleanly.
    $lineageAccount = $ingestion->createManualAccount($workspace, $user, [
        'name' => 'Delete Co',
        'website' => 'https://delete.example',
    ], 'delete-account');
    $lineagePerson = $ingestion->createManualPerson($workspace, $user, [
        'email' => 'delete@delete.example',
        'full_name' => 'Delete Person',
    ], 'delete-person');
    $accountObservationCount = SourceObservation::query()->where('account_id', $lineageAccount->id)->count();
    $personObservationCount = SourceObservation::query()->where('person_id', $lineagePerson->id)->count();

    app(AccountEditor::class)->delete($lineageAccount, $user);
    app(PersonEditor::class)->delete($lineagePerson, $user);

    expect(Account::query()->whereKey($lineageAccount->id)->exists())->toBeFalse()
        ->and(Person::query()->whereKey($lineagePerson->id)->exists())->toBeFalse()
        ->and(AccountSourceMembership::query()->where('account_id', $lineageAccount->id)->count())->toBe(0)
        ->and(PersonSourceMembership::query()->where('person_id', $lineagePerson->id)->count())->toBe(0)
        ->and(SourceObservation::query()->where('account_id', $lineageAccount->id)->count())->toBe(0)
        ->and(SourceObservation::query()->where('person_id', $lineagePerson->id)->count())->toBe(0)
        ->and($accountObservationCount)->toBeGreaterThan(0)
        ->and($personObservationCount)->toBeGreaterThan(0);
});

it('retains a workspace-global identity when another source still supports it', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $makeProviderSource = function (string $name, string $provider = 'fixture') use ($workspace): ProspectSource {
        $source = ProspectSource::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => ProspectSourceKind::Manual,
            'display_name' => $name,
            'name_normalized' => mb_strtolower($name),
            'active_name_normalized' => mb_strtolower($name),
        ]);
        $version = ProspectSourceVersion::factory()->forSource($source)->create([
            'configuration' => ['provider' => $provider],
        ]);
        $source->forceFill(['current_version_id' => $version->id])->save();

        return $source->fresh(['currentVersion']);
    };
    $sourceA = $makeProviderSource('Provider A');
    $sourceB = $makeProviderSource('Provider B');
    $ingestion = app(ProspectIngestionService::class);
    $values = ['name' => 'Shared Co', 'website' => 'https://shared.example'];

    $first = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $sourceA,
        sourceVersion: null,
        idempotencyKey: 'identity-source-a',
        observations: [new IngestionObservation('account', $values, providerRecordId: 'shared-1', observationKey: 'identity-a')],
    ));
    $account = $first->accounts[0];
    $second = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $sourceB,
        sourceVersion: null,
        idempotencyKey: 'identity-source-b',
        observations: [new IngestionObservation('account', $values, providerRecordId: '  SHARED-1  ', observationKey: 'identity-b')],
    ));
    $sourceC = $makeProviderSource('Provider C', 'other-fixture');
    $third = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $sourceC,
        sourceVersion: null,
        idempotencyKey: 'identity-source-c',
        observations: [new IngestionObservation('account', $values, providerRecordId: 'SHARED-1', observationKey: 'identity-c')],
    ));
    $second->observations[0]->forceFill(['provider_record_id' => '  SHARED-1  '])->save();

    expect($second->accounts[0]->id)->toBe($account->id)
        ->and($third->accounts[0]->id)->toBe($account->id)
        ->and(ExternalIdentity::query()->where('value_hash', hash('sha256', 'shared-1'))->count())->toBe(2);

    app(DeleteProspectSourceData::class)->handle($sourceA);
    $identity = ExternalIdentity::query()
        ->where('provider', 'fixture')
        ->where('value_hash', hash('sha256', 'shared-1'))
        ->first();

    expect($identity)->not->toBeNull()
        ->and($identity->last_source_observation_id)->toBe($second->observations[0]->id)
        ->and(ExternalIdentity::query()->where('provider', 'other-fixture')->where('value_hash', hash('sha256', 'shared-1'))->exists())->toBeTrue()
        ->and(AccountSourceMembership::query()->where('account_id', $account->id)->count())->toBe(2);
});
