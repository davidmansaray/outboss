<?php

namespace App\Domain\Campaigns\Services;

use App\Domain\Campaigns\DTOs\EnrollmentCandidate;
use App\Domain\Campaigns\Enums\ImportRowIssue;
use App\Domain\Campaigns\Enums\ImportStatus;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use App\Domain\People\Services\MatchOrCreateImportedAccount;
use App\Domain\Prospecting\DTOs\IngestionBatch;
use App\Domain\Prospecting\DTOs\IngestionObservation;
use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Domain\Prospecting\Services\ProspectIngestionService;
use App\Models\Campaign;
use App\Models\Person;
use App\Models\RecipientImport;
use App\Models\RecipientImportRow;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CommitRecipientImport
{
    public function __construct(
        private EnrollPeopleInCampaign $enroll,
        private FrozenMailExperimentInputGuard $frozenInputs,
        private GovernedCampaignInputGuard $governedInputs,
        private CreateProspectSource $sources,
        private ProspectIngestionService $ingestion,
    ) {}

    public function handle(RecipientImport $import, int $expectedMappingVersion): RecipientImport
    {
        $this->governedInputs->assertRecipientImportMutable($import);

        if ($import->mapping_version !== $expectedMappingVersion || ! $this->isPreviewed($import)) {
            throw new HttpException(409, 'Review this import again before committing.');
        }

        return DB::transaction(function () use ($import) {
            $campaign = Campaign::query()
                ->whereKey($import->getAttribute('campaign_id'))
                ->lockForUpdate()
                ->firstOrFail();
            $workspaceId = (int) $campaign->getAttribute('workspace_id');

            if ((int) $import->getAttribute('workspace_id') !== $workspaceId) {
                throw new HttpException(409, 'This import does not belong to the campaign workspace.');
            }

            // Check before touching the workspace person registry.  An import
            // prepared before freeze must not become a side channel for
            // adding audience rows after the experiment was launched.
            $this->frozenInputs->assertCampaignAudienceMutable($campaign);

            $workspace = $campaign->workspace()->firstOrFail();
            $rows = $this->rows($import);
            $peopleByEmail = $this->ingestRows($import, $workspace, $rows);

            $candidates = [];

            foreach ($rows as $row) {
                if (! $this->isReady($row)) {
                    continue;
                }

                $person = $peopleByEmail[(string) $row->email_normalized] ?? null;
                $candidates[] = new EnrollmentCandidate(
                    personId: $person instanceof Person ? $person->id : null,
                    accountId: $person instanceof Person ? $person->account_id : null,
                    emailAddress: (string) $row->email_address,
                    emailNormalized: (string) $row->email_normalized,
                    fullName: $row->full_name,
                    firstName: $row->first_name,
                    company: $row->company,
                );
            }

            $result = $this->enroll->handle($campaign, $candidates);

            $import->rows()->delete();
            $import->forceFill([
                'status' => ImportStatus::Completed,
                'imported' => $result->enrolled,
            ])->save();

            return $import->fresh();
        });
    }

    /**
     * @param  list<RecipientImportRow>  $rows
     * @return array<string, Person>
     */
    private function ingestRows(RecipientImport $import, Workspace $workspace, array $rows): array
    {
        $accountMatcher = new MatchOrCreateImportedAccount($workspace);
        $observations = [];
        foreach ($rows as $row) {
            if (! $this->shouldUpsert($row)) {
                continue;
            }
            $account = $accountMatcher->handle((string) $row->email_address, $row->company);
            $values = [
                'email' => (string) $row->email_address,
                'email_address' => (string) $row->email_address,
                'email_normalized' => (string) $row->email_normalized,
                'full_name' => $row->full_name,
                'first_name' => $row->first_name,
            ];
            if ($row->company !== null && trim((string) $row->company) !== '') {
                $values['company'] = $row->company;
            }
            if ($account !== null) {
                $values['account_id'] = $account->id;
            }
            $observations[] = new IngestionObservation(
                entityType: 'person',
                observedValues: $values,
                rowNumber: (int) $row->row_number,
                inputKey: 'recipient-import-row:'.$row->id,
                observationKey: hash('sha256', 'recipient-import|'.$import->id.'|'.$row->id),
            );
        }
        if ($observations === []) {
            return [];
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
                'import_motion' => 'campaign_recipients',
            ],
            actor: $actor,
            origin: 'user',
            systemKey: 'recipient-import:'.$import->id,
        );
        $this->ingestion->handle(new IngestionBatch(
            workspace: $workspace,
            source: $source,
            sourceVersion: $source->currentVersion()->first(),
            idempotencyKey: 'recipient-import:'.$import->id,
            observations: $observations,
            inputReference: ['import_id' => $import->id, 'adapter' => 'campaign_recipients'],
            actor: $actor,
            initiatorType: 'user',
            recipientImportId: (int) $import->id,
            requestFingerprint: hash('sha256', $import->source_hash.'|'.$import->mapping_version),
        ));

        $peopleByEmail = [];
        foreach ($rows as $row) {
            if ($this->shouldUpsert($row)) {
                $person = Person::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('email_normalized', $row->email_normalized)
                    ->first();
                if ($person instanceof Person) {
                    $peopleByEmail[(string) $row->email_normalized] = $person;
                }
            }
        }

        return $peopleByEmail;
    }

    private function sourceName(RecipientImport $import): string
    {
        $filename = trim((string) $import->original_filename);
        $name = $filename === '' ? 'Campaign import #'.$import->id : 'Campaign import · '.$filename;

        return mb_strcut($name, 0, 160, 'UTF-8');
    }

    /** @return list<RecipientImportRow> */
    private function rows(RecipientImport $import): array
    {
        $rows = [];
        foreach ($import->rows()->orderBy('row_number')->get() as $row) {
            if ($row instanceof RecipientImportRow) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private function headers(RecipientImport $import): array
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
    private function mapping(RecipientImport $import): array
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

    private function shouldUpsert(RecipientImportRow $row): bool
    {
        $issue = $row->getAttribute('issue');

        return $issue === ImportRowIssue::Ready
            || $issue === ImportRowIssue::Ready->value
            || $issue === ImportRowIssue::Suppressed
            || $issue === ImportRowIssue::Suppressed->value;
    }

    private function isReady(RecipientImportRow $row): bool
    {
        $issue = $row->getAttribute('issue');

        return $issue === ImportRowIssue::Ready
            || $issue === ImportRowIssue::Ready->value;
    }

    private function isPreviewed(RecipientImport $import): bool
    {
        $status = $import->getAttribute('status');

        return $status === ImportStatus::Previewed
            || $status === ImportStatus::Previewed->value;
    }
}
