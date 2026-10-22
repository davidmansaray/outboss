<?php

namespace App\Console\Commands;

use App\Domain\Prospecting\DTOs\IngestionBatch;
use App\Domain\Prospecting\DTOs\IngestionObservation;
use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Domain\Prospecting\Services\ProspectIngestionService;
use App\Models\Account;
use App\Models\Person;
use App\Models\ProspectSource;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

final class BackfillProspectSourcesCommand extends Command
{
    protected $signature = 'prospecting:backfill-sources
        {--workspace= : Backfill only this numeric workspace ID}
        {--chunk-size=200 : Number of records processed per resumable chunk}
        {--chunk= : Alias for --chunk-size}
        {--resume : Resume existing deterministic runs}
        {--dry-run : Report legacy coverage without writing lineage}';

    protected $description = 'Create honest source lineage for legacy Accounts and People';

    public function handle(CreateProspectSource $sources, ProspectIngestionService $ingestion): int
    {
        $workspaceId = $this->option('workspace');
        if ($workspaceId !== null && $workspaceId !== '' && preg_match('/^[1-9][0-9]*$/', (string) $workspaceId) !== 1) {
            $this->error('The --workspace option must be a positive numeric workspace ID.');

            return self::INVALID;
        }
        $chunkOption = $this->option('chunk');
        $chunkValue = $chunkOption !== null && $chunkOption !== ''
            ? $chunkOption
            : $this->option('chunk-size');
        $chunkSize = filter_var($chunkValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if (! is_int($chunkSize)) {
            $this->error('The --chunk-size option must be an integer from 1 to 1000.');

            return self::INVALID;
        }

        $query = Workspace::query()->orderBy('id');
        if ($workspaceId !== null && $workspaceId !== '') {
            $query->whereKey((int) $workspaceId);
        }
        $workspaces = $query->get();
        if ($workspaceId !== null && $workspaceId !== '' && $workspaces->isEmpty()) {
            $this->error("Workspace {$workspaceId} was not found.");

            return self::FAILURE;
        }

        $summary = ['workspaces' => 0, 'records' => 0, 'sources' => 0, 'runs' => 0];
        $resume = (bool) $this->option('resume');
        if ($resume) {
            $this->comment('Resuming deterministic source runs where observations already exist.');
        }
        foreach ($workspaces as $workspace) {
            $summary['workspaces']++;
            foreach ($this->classes($workspace) as $class => $counts) {
                if ($counts['total'] === 0) {
                    continue;
                }
                $summary['records'] += $counts['total'];
                if ($this->option('dry-run')) {
                    $this->line("workspace {$workspace->id} / {$class}: {$counts['total']} record(s) would be linked");

                    continue;
                }
                $source = $sources->ensureSystemSource(
                    $workspace,
                    $class,
                    null,
                    $this->kind($class),
                );
                $summary['sources']++;
                $ids = [
                    'accounts' => $this->legacyQuery(Account::class, $workspace, $counts['source_values'])->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    'people' => $this->legacyQuery(Person::class, $workspace, $counts['source_values'])->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                ];
                // The logical backfill request is the workspace/source class,
                // not a snapshot of row IDs. New legacy rows discovered after
                // an interrupted/previous run can therefore be appended to the
                // same deterministic run; each row still has its own stable
                // observation key.
                $fingerprint = hash('sha256', json_encode(['workspace' => $workspace->id, 'class' => $class, 'backfill_schema' => 1], JSON_THROW_ON_ERROR));
                $summary['runs']++;
                $this->backfillEntities($workspace, $source, $ingestion, $class, $chunkSize, $fingerprint, $ids, $resume);
            }
        }
        $this->info(sprintf(
            'Scanned %d workspace(s): %d legacy record(s), %d source(s), %d deterministic run(s).',
            $summary['workspaces'], $summary['records'], $summary['sources'], $summary['runs'],
        ));

        return self::SUCCESS;
    }

    /** @return array<string,array{total:int,source_values:list<?string>}> */
    private function classes(Workspace $workspace): array
    {
        $classes = [
            'legacy_manual' => ['manual'],
            'legacy_import' => ['import'],
            'legacy_campaign_backfill' => ['campaign_backfill'],
            'unknown_legacy_source' => [],
        ];
        $legacyValues = array_merge(
            Account::query()->where('workspace_id', $workspace->id)->where(function ($query): void {
                $query->whereNull('source')->orWhereNotIn('source', ['manual', 'import', 'campaign_backfill']);
            })->pluck('source')->map(fn ($value): ?string => $value === null ? null : (string) $value)->all(),
            Person::query()->where('workspace_id', $workspace->id)->where(function ($query): void {
                $query->whereNull('source')->orWhereNotIn('source', ['manual', 'import', 'campaign_backfill']);
            })->pluck('source')->map(fn ($value): ?string => $value === null ? null : (string) $value)->all(),
        );
        $unknown = [];
        $seenUnknown = [];
        foreach ($legacyValues as $value) {
            // Prefix the type so null and an empty legacy string remain
            // distinct source values (array_unique's string comparison
            // collapses them).
            $key = $value === null ? 'null' : 'value:'.$value;
            if (isset($seenUnknown[$key])) {
                continue;
            }
            $seenUnknown[$key] = true;
            $unknown[] = $value;
        }
        $result = [];
        foreach ($classes as $class => $sourceValues) {
            $sourceValues = $class === 'unknown_legacy_source' ? $unknown : $sourceValues;
            $accounts = $sourceValues === [] ? 0 : $this->legacyQuery(Account::class, $workspace, $sourceValues)->count();
            $people = $sourceValues === [] ? 0 : $this->legacyQuery(Person::class, $workspace, $sourceValues)->count();
            $result[$class] = ['total' => $accounts + $people, 'source_values' => $sourceValues];
        }

        return $result;
    }

    private function kind(string $class): ProspectSourceKind
    {
        return match ($class) {
            'legacy_manual' => ProspectSourceKind::Manual,
            'legacy_import' => ProspectSourceKind::CsvImport,
            default => ProspectSourceKind::ExistingAccounts,
        };
    }

    /** @param array<string,mixed> $ids */
    private function backfillEntities(Workspace $workspace, ProspectSource $source, ProspectIngestionService $ingestion, string $class, int $chunkSize, string $fingerprint, array $ids, bool $resume): void
    {
        $values = array_flip($ids['accounts']);
        $accountQuery = Account::query()->where('workspace_id', $workspace->id)->whereIn('id', array_keys($values))->orderBy('id');
        $accountQuery->chunkById($chunkSize, function ($accounts) use ($workspace, $source, $ingestion, $class, $fingerprint, $resume): void {
            $observations = array_values($accounts->map(fn (Account $account): IngestionObservation => new IngestionObservation(
                entityType: 'account',
                observedValues: [
                    'name' => $account->name,
                    'website' => $account->website,
                    'domain_normalized' => $account->domain_normalized,
                    'legacy_source' => $account->source,
                    'historical_created_at' => $account->created_at?->toIso8601String(),
                    'canonical_account_id' => $account->id,
                ],
                inputKey: 'accounts:'.$account->id,
                observationKey: hash('sha256', $workspace->id.'|accounts|'.$account->id),
            ))->all());
            $this->ingestChunk($workspace, $source, $ingestion, $class, $fingerprint, $observations, $resume);
        });
        $personQuery = Person::query()->where('workspace_id', $workspace->id)->whereIn('id', array_keys(array_flip($ids['people'])))->orderBy('id');
        $personQuery->chunkById($chunkSize, function ($people) use ($workspace, $source, $ingestion, $class, $fingerprint, $resume): void {
            $observations = array_values($people->map(fn (Person $person): IngestionObservation => new IngestionObservation(
                entityType: 'person',
                observedValues: [
                    'email' => $person->email_address,
                    'full_name' => $person->full_name,
                    'first_name' => $person->first_name,
                    'job_title' => $person->job_title,
                    'account_id' => $person->account_id,
                    'legacy_source' => $person->source,
                    'historical_created_at' => $person->created_at?->toIso8601String(),
                    'canonical_person_id' => $person->id,
                ],
                inputKey: 'people:'.$person->id,
                observationKey: hash('sha256', $workspace->id.'|people|'.$person->id),
            ))->all());
            $this->ingestChunk($workspace, $source, $ingestion, $class, $fingerprint, $observations, $resume);
        });
    }

    /** @param list<IngestionObservation> $observations */
    private function ingestChunk(Workspace $workspace, ProspectSource $source, ProspectIngestionService $ingestion, string $class, string $fingerprint, array $observations, bool $resume): void
    {
        if ($observations === []) {
            return;
        }
        $ingestion->handle(new IngestionBatch(
            workspace: $workspace,
            source: $source,
            sourceVersion: $source->currentVersion,
            idempotencyKey: 'legacy-backfill:'.$workspace->id.':'.$class,
            observations: $observations,
            inputReference: ['record_count' => count($observations), 'resume' => $resume],
            initiatorType: 'system',
            requestFingerprint: $fingerprint,
        ));
    }

    /**
     * @param  class-string<Account|Person>  $model
     * @param  list<?string>  $sourceValues
     * @return Builder<Account>|Builder<Person>
     */
    private function legacyQuery(string $model, Workspace $workspace, array $sourceValues): Builder
    {
        $query = $model::query()->where('workspace_id', $workspace->id);
        $includesNull = in_array(null, $sourceValues, true);
        $values = array_values(array_filter($sourceValues, static fn (?string $value): bool => $value !== null));
        if ($includesNull) {
            $query->where(function (Builder $nested) use ($values): void {
                $nested->whereNull('source');
                if ($values !== []) {
                    $nested->orWhereIn('source', $values);
                }
            });
        } elseif ($values !== []) {
            $query->whereIn('source', $values);
        }

        return $query;
    }
}
