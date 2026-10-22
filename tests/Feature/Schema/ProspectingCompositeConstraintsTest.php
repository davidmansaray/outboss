<?php

use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Models\Account;
use App\Models\CanonicalFieldProvenance;
use App\Models\ExternalIdentity;
use App\Models\Person;
use App\Models\ProspectSource;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * @return array{firstAccount: Account, firstPerson: Person, firstRun: SourceRun, secondRun: SourceRun, secondObservation: SourceObservation}
 */
function createCrossWorkspaceCompositeConstraintFixtures(): array
{
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $firstWorkspace = $firstUser->currentWorkspace();
    $secondWorkspace = $secondUser->currentWorkspace();

    $firstAccount = Account::factory()->create(['workspace_id' => $firstWorkspace->id]);
    $firstPerson = Person::factory()->create(['workspace_id' => $firstWorkspace->id]);
    $firstSource = createCompositeConstraintTestSource($firstWorkspace, $firstUser, 'First workspace source');
    $secondSource = createCompositeConstraintTestSource($secondWorkspace, $secondUser, 'Second workspace source');
    $firstRun = SourceRun::factory()->forSource($firstSource)->create();
    $secondRun = SourceRun::factory()->forSource($secondSource)->create();
    $secondObservation = SourceObservation::factory()->forSourceRun($secondRun)->create();

    return [
        'firstAccount' => $firstAccount,
        'firstPerson' => $firstPerson,
        'firstRun' => $firstRun,
        'secondRun' => $secondRun,
        'secondObservation' => $secondObservation,
    ];
}

function createCompositeConstraintTestSource(
    Workspace $workspace,
    User $actor,
    string $displayName,
): ProspectSource {
    return app(CreateProspectSource::class)->handle(
        workspace: $workspace,
        kind: ProspectSourceKind::Manual,
        displayName: $displayName,
        actor: $actor,
    );
}

it('rejects cross-workspace account and person references on every affected lineage table', function (): void {
    $fixtures = createCrossWorkspaceCompositeConstraintFixtures();
    $firstAccount = $fixtures['firstAccount'];
    $firstPerson = $fixtures['firstPerson'];
    $secondRun = $fixtures['secondRun'];
    $secondObservation = $fixtures['secondObservation'];
    $secondWorkspaceId = $secondRun->workspace_id;

    $attempts = [
        'source observation account' => fn (): SourceObservation => SourceObservation::factory()
            ->forSourceRun($secondRun)
            ->create([
                'entity_type' => 'account',
                'account_id' => $firstAccount->id,
            ]),
        'source observation person' => fn (): SourceObservation => SourceObservation::factory()
            ->forSourceRun($secondRun)
            ->create([
                'entity_type' => 'person',
                'person_id' => $firstPerson->id,
            ]),
        'external identity account' => fn (): ExternalIdentity => ExternalIdentity::factory()->create([
            'workspace_id' => $secondWorkspaceId,
            'entity_type' => 'account',
            'account_id' => $firstAccount->id,
        ]),
        'external identity person' => fn (): ExternalIdentity => ExternalIdentity::factory()
            ->forPerson($firstPerson->id)
            ->create(['workspace_id' => $secondWorkspaceId]),
        'canonical provenance account' => fn (): CanonicalFieldProvenance => CanonicalFieldProvenance::factory()->create([
            'workspace_id' => $secondWorkspaceId,
            'entity_type' => 'account',
            'account_id' => $firstAccount->id,
            'source_observation_id' => $secondObservation->id,
            'source_run_id' => $secondRun->id,
        ]),
        'canonical provenance person' => fn (): CanonicalFieldProvenance => CanonicalFieldProvenance::factory()->create([
            'workspace_id' => $secondWorkspaceId,
            'entity_type' => 'person',
            'account_id' => null,
            'person_id' => $firstPerson->id,
            'source_observation_id' => $secondObservation->id,
            'source_run_id' => $secondRun->id,
        ]),
    ];

    foreach ($attempts as $attempt) {
        expect($attempt)->toThrow(QueryException::class);
    }
});

it('rolls the entity workspace foreign keys down and back up on supported drivers', function (): void {
    $driver = DB::getDriverName();

    expect($driver)->toBeIn(['sqlite', 'mysql', 'mariadb'])
        ->and(Schema::hasTable('source_observations'))->toBeTrue()
        ->and(Schema::hasTable('external_identities'))->toBeTrue()
        ->and(Schema::hasTable('canonical_field_provenance'))->toBeTrue();

    $hasCompositeForeignKey = static function (string $table, string $entityColumn, string $parentTable) use ($driver): bool {
        if ($driver === 'sqlite') {
            /** @var Collection<int, Collection<int, object{id: int|string, table: string, from: string, to: string}>> $foreignKeysByConstraint */
            $foreignKeysByConstraint = collect(DB::select("PRAGMA foreign_key_list('{$table}')"))->groupBy('id');

            return $foreignKeysByConstraint->contains(
                static function (Collection $foreignKeys) use ($entityColumn, $parentTable): bool {
                    return $foreignKeys->contains(
                        static fn (object $foreignKey): bool => $foreignKey->table === $parentTable
                            && $foreignKey->from === 'workspace_id'
                            && $foreignKey->to === 'workspace_id',
                    ) && $foreignKeys->contains(
                        static fn (object $foreignKey): bool => $foreignKey->table === $parentTable
                            && $foreignKey->from === $entityColumn
                            && $foreignKey->to === 'id',
                    );
                },
            );
        }

        /** @var Collection<int, Collection<int, object{CONSTRAINT_NAME: string, COLUMN_NAME: string, REFERENCED_COLUMN_NAME: string}>> $foreignKeysByConstraint */
        $foreignKeysByConstraint = collect(DB::select(
            <<<'SQL'
                SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND REFERENCED_TABLE_NAME = ?
                ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION
            SQL,
            [$table, $parentTable],
        ))->groupBy('CONSTRAINT_NAME');

        return $foreignKeysByConstraint->contains(
            static function (Collection $foreignKeys) use ($entityColumn): bool {
                return $foreignKeys->contains(
                    static fn (object $foreignKey): bool => $foreignKey->COLUMN_NAME === 'workspace_id'
                        && $foreignKey->REFERENCED_COLUMN_NAME === 'workspace_id',
                ) && $foreignKeys->contains(
                    static fn (object $foreignKey): bool => $foreignKey->COLUMN_NAME === $entityColumn
                        && $foreignKey->REFERENCED_COLUMN_NAME === 'id',
                );
            },
        );
    };

    $entityForeignKeys = [
        ['source_observations', 'account_id', 'accounts'],
        ['source_observations', 'person_id', 'people'],
        ['external_identities', 'account_id', 'accounts'],
        ['external_identities', 'person_id', 'people'],
        ['canonical_field_provenance', 'account_id', 'accounts'],
        ['canonical_field_provenance', 'person_id', 'people'],
    ];

    foreach ($entityForeignKeys as [$table, $entityColumn, $parentTable]) {
        expect($hasCompositeForeignKey($table, $entityColumn, $parentTable))->toBeTrue();
    }

    $invokeMigration = static function (string $method): void {
        $migration = require database_path('migrations/2026_09_03_100100_add_prospecting_composite_constraints.php');

        if (! is_object($migration) || ! method_exists($migration, $method)) {
            throw new LogicException("Composite constraint migration does not define {$method}().");
        }

        (new ReflectionMethod($migration, $method))->invoke($migration);
    };

    $invokeMigration('down');

    foreach ($entityForeignKeys as [$table, $entityColumn, $parentTable]) {
        expect($hasCompositeForeignKey($table, $entityColumn, $parentTable))->toBeFalse();
    }

    $invokeMigration('up');

    foreach ($entityForeignKeys as [$table, $entityColumn, $parentTable]) {
        expect($hasCompositeForeignKey($table, $entityColumn, $parentTable))->toBeTrue();
    }
});
