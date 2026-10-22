<?php

use App\Domain\Prospecting\DTOs\IngestionBatch;
use App\Domain\Prospecting\DTOs\IngestionObservation;
use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Domain\Prospecting\Services\ProspectIngestionService;
use App\Models\Account;
use App\Models\AccountSourceMembership;
use App\Models\CanonicalFieldProvenance;
use App\Models\Person;
use App\Models\PersonImport;
use App\Models\PersonSourceMembership;
use App\Models\ProspectSource;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('backfills more than one chunk and keeps repeat and resume runs idempotent', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();

    $accounts = Account::factory()->count(5)->create([
        'workspace_id' => $workspace->id,
        'source' => Account::SOURCE_MANUAL,
    ]);
    $people = Person::factory()->count(4)->create([
        'workspace_id' => $workspace->id,
        'source' => Person::SOURCE_MANUAL,
    ]);

    $this->artisan('prospecting:backfill-sources', [
        '--workspace' => (string) $workspace->id,
        '--chunk-size' => '2',
    ])->assertExitCode(0);

    $source = ProspectSource::query()
        ->where('workspace_id', $workspace->id)
        ->where('system_key', 'legacy_manual')
        ->firstOrFail();
    $run = SourceRun::query()
        ->where('workspace_id', $workspace->id)
        ->where('prospect_source_id', $source->id)
        ->firstOrFail();

    expect($run->state->value)->toBe('completed')
        ->and($run->observations()->count())->toBe(9)
        ->and(AccountSourceMembership::query()->where('workspace_id', $workspace->id)->count())->toBe(5)
        ->and(PersonSourceMembership::query()->where('workspace_id', $workspace->id)->count())->toBe(4)
        ->and(Account::query()->where('workspace_id', $workspace->id)->count())->toBe(5)
        ->and(Person::query()->where('workspace_id', $workspace->id)->count())->toBe(4);

    $accountObservation = SourceObservation::query()
        ->where('source_run_id', $run->id)
        ->where('account_id', $accounts->firstOrFail()->id)
        ->firstOrFail();
    expect($accountObservation->disposition->value)->toBe('linked')
        ->and($accountObservation->provider_observed_at)->toBeNull()
        ->and($accountObservation->observed_values['legacy_source'])->toBe(Account::SOURCE_MANUAL)
        ->and($accountObservation->observed_values['canonical_account_id'])->toBe((string) $accounts->firstOrFail()->id)
        ->and($accountObservation->observed_values['historical_created_at'])->not->toBeNull();

    $runId = (string) $run->id;
    $observationIds = SourceObservation::query()->where('source_run_id', $runId)->pluck('id')->all();

    // A repeat without --resume sees all current chunk keys and replays the
    // completed deterministic run rather than creating a second run/history.
    $this->artisan('prospecting:backfill-sources', [
        '--workspace' => (string) $workspace->id,
        '--chunk-size' => '2',
    ])->assertExitCode(0);

    expect(SourceRun::query()->where('workspace_id', $workspace->id)->where('prospect_source_id', $source->id)->count())->toBe(1)
        ->and(SourceObservation::query()->where('source_run_id', $runId)->count())->toBe(9)
        ->and(SourceObservation::query()->where('source_run_id', $runId)->pluck('id')->all())->toBe($observationIds);

    $newAccount = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'source' => Account::SOURCE_MANUAL,
    ]);

    // Resume appends a newly discovered legacy row to the same logical run;
    // existing rows keep their original observations and memberships.
    $this->artisan('prospecting:backfill-sources', [
        '--workspace' => (string) $workspace->id,
        '--chunk-size' => '2',
        '--resume' => true,
    ])->assertExitCode(0);

    expect(SourceRun::query()->where('workspace_id', $workspace->id)->where('prospect_source_id', $source->id)->count())->toBe(1)
        ->and(SourceRun::query()->whereKey($runId)->value('id'))->toBe($runId)
        ->and(SourceObservation::query()->where('source_run_id', $runId)->count())->toBe(10)
        ->and(AccountSourceMembership::query()->where('account_id', $newAccount->id)->count())->toBe(1);

    $this->artisan('prospecting:reconcile-sources', [
        '--workspace' => (string) $workspace->id,
    ])->assertExitCode(0)
        ->expectsOutput('workspace '.$workspace->id.': 0 mismatch(es)')
        ->expectsOutput('Total mismatches: 0.');
});

it('retains unknown and null legacy source values and links a canonical account without a domain', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();

    $noDomain = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'No Domain Holdings',
        'website' => null,
        'domain_normalized' => null,
        'source' => Account::SOURCE_MANUAL,
    ]);
    $unknownAccount = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'source' => 'historical_partner_feed',
    ]);
    $nullAccount = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'source' => null,
    ]);
    $emptyAccount = Account::factory()->create([
        'workspace_id' => $workspace->id,
        'source' => '',
    ]);
    $unknownPerson = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'source' => 'old_enrichment_job',
    ]);
    $nullPerson = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'source' => null,
    ]);

    $this->artisan('prospecting:backfill-sources', [
        '--workspace' => (string) $workspace->id,
        '--chunk-size' => '1',
    ])->assertExitCode(0);

    $unknownSource = ProspectSource::query()
        ->where('workspace_id', $workspace->id)
        ->where('system_key', 'unknown_legacy_source')
        ->firstOrFail();
    $unknownObservations = SourceObservation::query()
        ->where('prospect_source_id', $unknownSource->id)
        ->get();

    $byAccount = $unknownObservations->firstWhere('account_id', $unknownAccount->id);
    $nullAccountObservation = $unknownObservations->firstWhere('account_id', $nullAccount->id);
    $byPerson = $unknownObservations->firstWhere('person_id', $unknownPerson->id);
    $nullPersonObservation = $unknownObservations->firstWhere('person_id', $nullPerson->id);
    $emptyObservation = $unknownObservations->firstWhere('account_id', $emptyAccount->id);

    expect($unknownObservations)->toHaveCount(5)
        ->and($byAccount?->observed_values['legacy_source'])->toBe('historical_partner_feed')
        ->and($nullAccountObservation?->observed_values)->toHaveKey('legacy_source')
        ->and($nullAccountObservation?->observed_values['legacy_source'])->toBeNull()
        ->and($emptyObservation?->observed_values)->toHaveKey('legacy_source')
        ->and($emptyObservation?->observed_values['legacy_source'])->toBe('')
        ->and($byPerson?->observed_values['legacy_source'])->toBe('old_enrichment_job')
        ->and($nullPersonObservation?->observed_values)->toHaveKey('legacy_source')
        ->and($nullPersonObservation?->observed_values['legacy_source'])->toBeNull()
        ->and($unknownAccount->fresh()->source)->toBe('historical_partner_feed')
        ->and($nullAccount->fresh()->source)->toBeNull()
        ->and($unknownPerson->fresh()->source)->toBe('old_enrichment_job')
        ->and($nullPerson->fresh()->source)->toBeNull();

    $noDomainObservation = SourceObservation::query()
        ->where('account_id', $noDomain->id)
        ->firstOrFail();
    expect($noDomainObservation->disposition->value)->toBe('linked')
        ->and($noDomainObservation->observed_values['canonical_account_id'])->toBe((string) $noDomain->id)
        ->and($noDomain->fresh()->domain_normalized)->toBeNull()
        ->and(Account::query()->where('workspace_id', $workspace->id)->count())->toBe(4)
        ->and(AccountSourceMembership::query()->where('account_id', $noDomain->id)->count())->toBe(1);

    $this->artisan('prospecting:reconcile-sources', [
        '--workspace' => (string) $workspace->id,
    ])->assertExitCode(0);
});

it('rejects a cross-workspace source run through the composite foreign key', function (): void {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $firstWorkspace = $firstUser->currentWorkspace();
    $secondWorkspace = $secondUser->currentWorkspace();
    $source = app(CreateProspectSource::class)->handle(
        workspace: $firstWorkspace,
        kind: ProspectSourceKind::Manual,
        displayName: 'First workspace source',
        actor: $firstUser,
    );

    $run = SourceRun::factory()->forSource($source)->make([
        'workspace_id' => $secondWorkspace->id,
        'idempotency_key' => 'cross-workspace-run',
    ]);

    expect(fn (): bool => $run->save())->toThrow(QueryException::class);
});

it('retains the matched account while applying an unprovenanced import name and protects a user field', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $accountA = Account::factory()->create(['workspace_id' => $workspace->id]);
    $accountB = Account::factory()->create(['workspace_id' => $workspace->id]);
    $source = app(CreateProspectSource::class)->handle(
        workspace: $workspace,
        kind: ProspectSourceKind::CsvImport,
        displayName: 'Conflict import',
        configuration: ['mapping' => ['email' => 'email']],
        actor: $owner,
    );
    $ingestion = app(ProspectIngestionService::class);
    $personImport = PersonImport::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);

    $unprovenanced = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'conflict-seed-unprovenanced',
        observations: [new IngestionObservation(
            entityType: 'person',
            observedValues: [
                'email' => 'unprovenanced@example.test',
                'full_name' => 'Original Import Name',
                'account_id' => $accountA->id,
            ],
        )],
    ));
    $unprovenancedPerson = $unprovenanced->people[0];

    $conflict = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'conflict-import-unprovenanced',
        observations: [new IngestionObservation(
            entityType: 'person',
            observedValues: [
                'email' => 'unprovenanced@example.test',
                'full_name' => 'Imported Replacement Name',
                'account_id' => $accountB->id,
            ],
        )],
        personImportId: (int) $personImport->id,
        initiatorType: 'user',
        actor: $owner,
    ));

    expect($conflict->observations[0]->disposition->value)->toBe('conflicted')
        ->and($unprovenancedPerson->fresh()->account_id)->toBe($accountA->id)
        ->and($unprovenancedPerson->fresh()->full_name)->toBe('Imported Replacement Name')
        ->and(CanonicalFieldProvenance::query()
            ->where('person_id', $unprovenancedPerson->id)
            ->where('field_path', 'full_name')
            ->latest('id')
            ->firstOrFail()
            ->decision
            ->value)->toBe('applied');

    $userMaintained = $ingestion->createManualPerson($workspace, $owner, [
        'email' => 'user-maintained@example.test',
        'full_name' => 'Owner Supplied Name',
        'account_id' => $accountA->id,
    ], 'conflict-seed-user-maintained');
    expect(CanonicalFieldProvenance::query()
        ->where('person_id', $userMaintained->id)
        ->where('field_path', 'full_name')
        ->where('actor_type', 'user')
        ->where('decision', 'applied')
        ->exists())->toBeTrue();

    $protectedImport = PersonImport::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);
    $protectedConflict = $ingestion->handle(new IngestionBatch(
        workspace: $workspace,
        source: $source,
        sourceVersion: null,
        idempotencyKey: 'conflict-import-user-maintained',
        observations: [new IngestionObservation(
            entityType: 'person',
            observedValues: [
                'email' => 'user-maintained@example.test',
                'full_name' => 'Import Must Not Replace Owner',
                'account_id' => $accountB->id,
            ],
        )],
        personImportId: (int) $protectedImport->id,
        initiatorType: 'user',
        actor: $owner,
    ));

    expect($protectedConflict->observations[0]->disposition->value)->toBe('conflicted')
        ->and($userMaintained->fresh()->account_id)->toBe($accountA->id)
        ->and($userMaintained->fresh()->full_name)->toBe('Owner Supplied Name');
});

it('can roll the SQLite composite migration down and up while lineage tables remain', function (): void {
    expect(DB::getDriverName())->toBe('sqlite')
        ->and(Schema::hasTable('prospect_sources'))->toBeTrue()
        ->and(Schema::hasTable('source_runs'))->toBeTrue();

    $hasWorkspaceSourceForeignKey = static function (): bool {
        return collect(DB::select("PRAGMA foreign_key_list('source_runs')"))->contains(
            static fn (object $foreignKey): bool => $foreignKey->table === 'prospect_sources'
                && $foreignKey->from === 'workspace_id'
                && $foreignKey->to === 'workspace_id',
        );
    };

    expect($hasWorkspaceSourceForeignKey())->toBeTrue();

    /** @var object{down: callable, up: callable} $migration */
    $migration = require database_path('migrations/2026_09_03_100100_add_prospecting_composite_constraints.php');
    $migration->down();
    expect($hasWorkspaceSourceForeignKey())->toBeFalse();

    $migration->up();
    expect($hasWorkspaceSourceForeignKey())->toBeTrue();
});
