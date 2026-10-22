<?php

namespace App\Domain\People\Services;

use App\Domain\Campaigns\Enums\ImportStatus;
use App\Domain\Prospecting\DTOs\IngestionBatch;
use App\Domain\Prospecting\DTOs\IngestionObservation;
use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Domain\Prospecting\Services\ProspectIngestionService;
use App\Models\PersonImport;
use App\Models\PersonImportRow;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CommitPersonImport
{
    public function __construct(
        private readonly CreateProspectSource $sources,
        private readonly ProspectIngestionService $ingestion,
    ) {}

    public function handle(PersonImport $import, int $expectedMappingVersion): PersonImport
    {
        if ($import->mapping_version !== $expectedMappingVersion || ! $this->isPreviewed($import)) {
            throw new HttpException(409, 'Review this import again before committing.');
        }

        return DB::transaction(function () use ($import) {
            $workspace = $import->workspace()->firstOrFail();
            $rows = $import->rows()->orderBy('row_number')->get();
            $imported = $rows->where('action', '!=', PersonImportRow::ACTION_SKIP)->count();

            $this->ingestRows($import, $workspace, $rows);

            $import->rows()->delete();
            $import->forceFill([
                'status' => ImportStatus::Completed,
                'imported' => $imported,
            ])->save();

            return $import->fresh();
        });
    }

    /** @param Collection<int, PersonImportRow> $rows */
    private function ingestRows(PersonImport $import, Workspace $workspace, Collection $rows): void
    {
        $accountMatcher = new MatchOrCreateImportedAccount($workspace);
        $observations = [];
        foreach ($rows as $row) {
            if ($row->action === PersonImportRow::ACTION_SKIP) {
                continue;
            }

            $account = $accountMatcher->handle((string) $row->email_address, $row->company);
            $values = [
                'email' => (string) $row->email_address,
                'email_address' => (string) $row->email_address,
                'email_normalized' => (string) $row->email_normalized,
                'full_name' => $row->full_name,
                'first_name' => $row->first_name,
                'job_title' => $row->job_title,
                'tags' => $row->tags ?? [],
            ];
            if ($account !== null) {
                $values['account_id'] = $account->id;
            }
            $observations[] = new IngestionObservation(
                entityType: 'person',
                observedValues: $values,
                rowNumber: (int) $row->row_number,
                inputKey: 'person-import-row:'.$row->id,
                observationKey: hash('sha256', 'person-import|'.$import->id.'|'.$row->id),
            );
        }
        if ($observations === []) {
            return;
        }

        $mapping = $this->mapping($import);
        $headers = $this->headers($import);
        $actor = User::query()->where('id', $import->getAttribute('user_id'))->first();
        $source = $this->sources->handle(
            workspace: $workspace,
            kind: ProspectSourceKind::CsvImport,
            displayName: $this->sourceName($import),
            configuration: [
                'filename' => $import->original_filename,
                'input_sha256' => $import->source_hash,
                'headers' => $headers,
                'mapping' => $mapping === [] ? ['email' => 'email'] : $mapping,
                'import_motion' => 'people',
            ],
            actor: $actor,
            origin: 'user',
            systemKey: 'person-import:'.$import->id,
        );
        $this->ingestion->handle(new IngestionBatch(
            workspace: $workspace,
            source: $source,
            sourceVersion: $source->currentVersion()->first(),
            idempotencyKey: 'person-import:'.$import->id,
            observations: $observations,
            inputReference: ['import_id' => $import->id, 'adapter' => 'people_csv'],
            actor: $actor,
            initiatorType: 'user',
            personImportId: (int) $import->id,
            requestFingerprint: hash('sha256', $import->source_hash.'|'.$import->mapping_version),
        ));
    }

    private function sourceName(PersonImport $import): string
    {
        $filename = trim((string) $import->original_filename);
        $name = $filename === '' ? 'People import #'.$import->id : 'People import · '.$filename;

        return mb_strcut($name, 0, 160, 'UTF-8');
    }

    /** @return list<string> */
    private function headers(PersonImport $import): array
    {
        $headers = $import->getAttribute('headers');
        if (! is_array($headers)) {
            return [];
        }

        $result = [];
        foreach ($headers as $header) {
            if (is_string($header)) {
                $result[] = $header;
            }
        }

        return $result;
    }

    /** @return array<string, string> */
    private function mapping(PersonImport $import): array
    {
        $mapping = $import->getAttribute('mapping');
        if (! is_array($mapping)) {
            return [];
        }

        $result = [];
        foreach ($mapping as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function isPreviewed(PersonImport $import): bool
    {
        $status = $import->getAttribute('status');

        return $status === ImportStatus::Previewed
            || $status === ImportStatus::Previewed->value;
    }
}
