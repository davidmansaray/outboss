<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\People\Support\ProspectSourceSurface;
use App\Domain\Prospecting\DTOs\IngestionBatch;
use App\Domain\Prospecting\DTOs\IngestionObservation;
use App\Domain\Prospecting\Services\ProspectIngestionService;
use App\Domain\Prospecting\Services\StartSourceRun as StartSourceRunService;
use App\Models\ProspectSource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Starts a bounded prospect source run for existing-record selections or supplied rows. Owner access is required; unavailable providers and credentials cannot be configured here.')]
class StartProspectSourceRun extends OutbossTool
{
    public function __construct(
        private StartSourceRunService $start,
        private ProspectIngestionService $ingestion,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'source_id' => $schema->string()->required()->description('Prospect source UUID.'),
            'idempotency_key' => $schema->string()->required()->description('Stable retry key, maximum 160 characters.'),
            'input_reference' => $schema->object()->description('Bounded selection reference.'),
            'input' => $schema->array()->max(1000)->description('Optional bounded input rows.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::SourcesWrite->value)) {
            return $denied;
        }

        $user = $this->actor($request);
        $workspace = $user->currentWorkspace();
        if (! $workspace->isOwner($user)) {
            return Response::error('Only workspace owners can start prospect source runs.');
        }

        $data = $request->validate([
            'source_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            'input_reference' => ['sometimes', 'array', 'max:20'],
            'input' => ['sometimes', 'array', 'max:1000'],
        ]);
        $source = ProspectSourceSurface::find($workspace, $data['source_id']);
        if ($source === null) {
            return Response::error('Prospect source not found.');
        }

        $sourceModel = ProspectSource::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($data['source_id'])
            ->first();
        if ($sourceModel === null) {
            return Response::error('Prospect source not found.');
        }
        if (($data['input'] ?? []) !== []) {
            $result = $this->ingestion->handle(new IngestionBatch(
                workspace: $workspace,
                source: $sourceModel,
                sourceVersion: null,
                idempotencyKey: $data['idempotency_key'],
                observations: $this->observations($data['input']),
                inputReference: $data['input_reference'] ?? ['transport' => 'mcp_source_run'],
                actor: $user,
                initiatorType: 'mcp',
            ));
            $run = $result->run;
        } else {
            $run = $this->start->handle(
                workspace: $workspace,
                source: $sourceModel,
                version: null,
                idempotencyKey: $data['idempotency_key'],
                input: $data['input_reference'] ?? [],
                actor: $user,
                initiatorType: 'mcp',
            );
        }

        $state = $run->getAttribute('state') ?? 'queued';
        if ($state instanceof \BackedEnum) {
            $state = $state->value;
        }

        return $this->json(['run' => [
            'id' => (string) ($run->id ?? ''),
            'state' => (string) $state,
        ]]);
    }

    /** @return list<IngestionObservation> */
    private function observations(mixed $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw ValidationException::withMessages([
                'input' => 'Observation rows must be a list of objects.',
            ]);
        }

        $observations = [];
        foreach ($rows as $rawRow) {
            if (! is_array($rawRow)) {
                throw ValidationException::withMessages([
                    'input' => 'Each observation row must be an object.',
                ]);
            }

            $row = [];
            foreach ($rawRow as $key => $value) {
                if (! is_string($key)) {
                    throw ValidationException::withMessages([
                        'input' => 'Each observation row must be an object.',
                    ]);
                }
                $row[$key] = $value;
            }
            $values = is_array($row['observed_values'] ?? null)
                ? $row['observed_values']
                : (is_array($row['values'] ?? null) ? $row['values'] : $row);

            $observations[] = new IngestionObservation(
                entityType: (string) ($row['entity_type'] ?? 'person'),
                observedValues: $values,
                rowNumber: isset($row['row_number']) ? (int) $row['row_number'] : null,
                inputKey: isset($row['input_key']) ? (string) $row['input_key'] : null,
                observationKey: isset($row['observation_key']) ? (string) $row['observation_key'] : null,
                rawInputHash: isset($row['raw_input_hash']) ? (string) $row['raw_input_hash'] : null,
                providerRecordId: isset($row['provider_record_id']) ? (string) $row['provider_record_id'] : null,
                providerSnapshotId: isset($row['provider_snapshot_id']) ? (string) $row['provider_snapshot_id'] : null,
                canonicalUrl: isset($row['canonical_url']) ? (string) $row['canonical_url'] : null,
                providerObservedAt: isset($row['provider_observed_at']) ? (string) $row['provider_observed_at'] : null,
                metadata: is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
            );
        }

        return $observations;
    }
}
