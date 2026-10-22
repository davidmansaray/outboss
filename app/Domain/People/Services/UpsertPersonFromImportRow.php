<?php

namespace App\Domain\People\Services;

use App\Domain\Mail\Support\EmailNormalizer;
use App\Domain\Prospecting\DTOs\IngestionBatch;
use App\Domain\Prospecting\DTOs\IngestionObservation;
use App\Domain\Prospecting\Enums\ProspectSourceKind;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Domain\Prospecting\Services\ProspectIngestionService;
use App\Models\Person;
use App\Models\PersonImportRow;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

class UpsertPersonFromImportRow
{
    public function __construct(
        private MatchOrCreateImportedAccount $accounts,
        private ?ProspectIngestionService $ingestion = null,
        private ?CreateProspectSource $sources = null,
    ) {}

    /**
     * @param  array<int, string>  $tags
     */
    public function handle(
        Workspace $workspace,
        string $email,
        ?string $fullName,
        ?string $jobTitle,
        ?string $company,
        array $tags,
        string $preferredAction,
        ?string $idempotencyKey = null,
    ): Person {
        $normalized = EmailNormalizer::normalize($email);
        $person = Person::query()
            ->where('workspace_id', $workspace->id)
            ->where('email_normalized', $normalized)
            ->first();

        $action = $person instanceof Person
            ? PersonImportRow::ACTION_UPDATE
            : PersonImportRow::ACTION_CREATE;

        if ($preferredAction === PersonImportRow::ACTION_SKIP) {
            $action = PersonImportRow::ACTION_SKIP;
        }

        if ($action === PersonImportRow::ACTION_SKIP) {
            if (! $person instanceof Person) {
                throw ValidationException::withMessages(['email' => 'The imported person could not be resolved.']);
            }

            return $person;
        }

        $account = null;
        if ($action === PersonImportRow::ACTION_CREATE) {
            $account = $this->accounts->handle($email, $company);
        } elseif ($person->account_id === null) {
            $account = $this->accounts->handle($email, $company);
        }

        $this->ingestion ??= app(ProspectIngestionService::class);
        $this->sources ??= app(CreateProspectSource::class);
        $source = $this->sources->ensureSystemSource(
            workspace: $workspace,
            systemKey: 'legacy_import',
            kind: ProspectSourceKind::CsvImport,
            displayName: 'Legacy import',
            configuration: [
                'mapping' => ['email' => 'email'],
                'import_motion' => 'people',
            ],
        );
        $key = $idempotencyKey ?? 'import-person:'.hash('sha256', implode('|', [
            $workspace->id,
            $normalized,
            $fullName ?? '',
            $jobTitle ?? '',
            implode(',', $tags),
            $account === null ? '' : (string) $account->id,
        ]));
        $values = [
            'email' => trim($email),
            'email_address' => trim($email),
            'email_normalized' => $normalized,
            'full_name' => $this->nullable($fullName),
            'first_name' => EmailNormalizer::firstName($fullName),
            'job_title' => $this->nullable($jobTitle),
            'tags' => $this->normalisedTags($tags),
        ];
        if ($account !== null) {
            $values['account_id'] = $account->id;
        }
        $result = $this->ingestion->handle(new IngestionBatch(
            workspace: $workspace,
            source: $source,
            sourceVersion: $source->currentVersion()->first(),
            idempotencyKey: $key,
            observations: [new IngestionObservation(
                entityType: 'person',
                observedValues: $values,
            )],
            inputReference: ['adapter' => 'people_import'],
            initiatorType: 'user',
        ));
        $canonical = $result->firstCanonical();
        if (! $canonical instanceof Person) {
            $safeMessage = isset($result->observations[0])
                ? $result->observations[0]->getAttribute('safe_error_message')
                : null;

            throw ValidationException::withMessages([
                'email' => is_string($safeMessage) && $safeMessage !== ''
                    ? $safeMessage
                    : 'The imported person was rejected.',
            ]);
        }

        return $canonical;
    }

    /**
     * @param  array<int, string>  $existing
     * @param  array<int, string>  $incoming
     * @return array<int, string>
     */
    private function mergeTags(array $existing, array $incoming): array
    {
        $seen = [];
        $merged = [];

        foreach ([...$existing, ...$incoming] as $tag) {
            $value = trim((string) $tag);

            if ($value === '') {
                continue;
            }

            $key = mb_strtolower($value);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $value;
        }

        return $merged;
    }

    /**
     * @param  array<int, mixed>  $tags
     * @return array<int, string>
     */
    private function normalisedTags(array $tags): array
    {
        return $this->mergeTags([], $tags);
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
