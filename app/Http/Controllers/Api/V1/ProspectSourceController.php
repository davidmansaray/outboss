<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\People\Support\ProspectSourceSurface;
use App\Domain\Prospecting\DTOs\IngestionBatch;
use App\Domain\Prospecting\DTOs\IngestionObservation;
use App\Domain\Prospecting\Services\CommitSourceRun;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Domain\Prospecting\Services\ProspectIngestionService;
use App\Domain\Prospecting\Services\StartSourceRun;
use App\Domain\Prospecting\Services\UpdateProspectSource;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProspectSourceResource;
use App\Models\Account;
use App\Models\Person;
use App\Models\ProspectSource;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ProspectSourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $sources = collect(ProspectSourceSurface::list($workspace));
        $search = trim((string) $request->query('query', $request->query('q', '')));

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $sources = $sources->filter(fn (array $source): bool => str_contains(mb_strtolower(
                implode(' ', [
                    (string) $source['display_name'],
                    (string) $source['kind'],
                    (string) $source['status'],
                ]),
            ), $needle));
        }

        $perPage = min(100, max(1, $request->integer('per_page', 15)));
        $page = max(1, $request->integer('page', 1));
        $total = $sources->count();
        $pageSources = $sources->forPage($page, $perPage)->values();

        return response()->json([
            'data' => ProspectSourceResource::collection($pageSources)->resolve(),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    public function store(Request $request, CreateProspectSource $create): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'string', 'max:60'],
            'configuration' => ['sometimes', 'array'],
            'policy' => ['sometimes', 'array'],
        ]);
        $source = $create->handle(
            workspace: $workspace,
            kind: $data['kind'],
            displayName: $data['display_name'],
            configuration: $data['configuration'] ?? [],
            policy: $data['policy'] ?? [],
            actor: $request->user(),
            origin: 'api',
        );
        $payload = ProspectSourceSurface::detail($workspace, (string) $source->getKey());

        return response()->json(['data' => (new ProspectSourceResource($payload ?? $source))->resolve()], 201);
    }

    public function show(Request $request, string $source): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $payload = ProspectSourceSurface::detail($workspace, $source);
        abort_if($payload === null, 404);

        return response()->json(['data' => new ProspectSourceResource($payload)]);
    }

    public function update(Request $request, string $source, UpdateProspectSource $update): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $sourceModel = $this->sourceModel($workspace, $source);
        $data = $request->validate([
            'display_name' => ['sometimes', 'required', 'string', 'max:120'],
            'configuration' => ['sometimes', 'array'],
            'policy' => ['sometimes', 'array'],
        ]);
        $update->handle(
            source: $sourceModel,
            displayName: $data['display_name'] ?? null,
            configuration: $data['configuration'] ?? null,
            policy: $data['policy'] ?? null,
            actor: $request->user(),
            origin: 'api',
        );

        return response()->json(['data' => (new ProspectSourceResource(
            ProspectSourceSurface::detail($workspace, $source) ?? [],
        ))->resolve()]);
    }

    public function runs(Request $request, string $source): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        abort_if(ProspectSourceSurface::find($workspace, $source) === null, 404);

        return response()->json(['data' => ProspectSourceSurface::runs($workspace, $source)->values()->all()]);
    }

    public function startRun(Request $request, string $source, StartSourceRun $start, ProspectIngestionService $ingestion): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $sourceModel = $this->sourceModel($workspace, $source);
        $data = $request->validate([
            'idempotency_key' => ['sometimes', 'string', 'max:160'],
            'input_reference' => ['sometimes', 'array', 'max:20'],
            'input' => ['sometimes', 'array', 'max:1000'],
        ]);
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? $request->header('Idempotency-Key', '')));
        abort_if($idempotencyKey === '', 400, 'Idempotency-Key header is required.');
        if (($data['input'] ?? []) !== []) {
            $result = $ingestion->handle(new IngestionBatch(
                workspace: $workspace,
                source: $sourceModel,
                sourceVersion: null,
                idempotencyKey: $idempotencyKey,
                observations: $this->buildObservations($data['input']),
                inputReference: $data['input_reference'] ?? ['transport' => 'api_source_run'],
                actor: $request->user(),
                initiatorType: 'api',
                requestFingerprint: hash('sha256', $request->getContent()),
            ));
            $run = $result->run;
        } else {
            $run = $start->handle(
                workspace: $workspace,
                source: $sourceModel,
                version: null,
                idempotencyKey: $idempotencyKey,
                input: $data['input_reference'] ?? [],
                actor: $request->user(),
                initiatorType: 'api',
            );
        }

        return response()->json(['data' => $this->runPayload($run)], 202);
    }

    public function showRun(Request $request, string $run): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $runRow = $this->runRow($workspace, $run);
        abort_if($runRow === null, 404);

        return response()->json(['data' => [
            ...$runRow,
            'observations' => ProspectSourceSurface::observations(
                $workspace,
                $run,
                max(1, $request->integer('page', 1)),
                min(100, max(1, $request->integer('per_page', 50))),
            ),
        ]]);
    }

    public function commitRun(Request $request, string $run, CommitSourceRun $commit): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $runModel = $this->runModel($workspace, $run);
        $result = $commit->handle($runModel);

        return response()->json(['data' => $this->runPayload($result)], 202);
    }

    public function observations(Request $request, string $run): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        abort_if($this->runRow($workspace, $run) === null, 404);

        return response()->json(ProspectSourceSurface::observations(
            $workspace,
            $run,
            max(1, $request->integer('page', 1)),
            min(100, max(1, $request->integer('per_page', 50))),
        ));
    }

    public function batch(Request $request, ProspectIngestionService $ingestion): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        abort_if($key === '', 400, 'Idempotency-Key header is required.');
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $data = $request->validate([
            'source_id' => ['required', 'uuid'],
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*' => ['required', 'array', 'max:30'],
        ]);
        $sourceModel = $this->sourceModel($workspace, (string) $data['source_id']);
        $observations = $this->buildObservations($data['rows']);
        $result = $ingestion->handle(new IngestionBatch(
            workspace: $workspace,
            source: $sourceModel,
            sourceVersion: null,
            idempotencyKey: $key,
            observations: $observations,
            inputReference: ['transport' => 'rest_batch'],
            actor: $request->user(),
            initiatorType: 'api',
            requestFingerprint: hash('sha256', $request->getContent()),
        ));

        return response()->json(['data' => [
            ...$this->runPayload($result->run),
            'run_id' => (string) $result->run->id,
            'observation_ids' => array_map(static fn (SourceObservation $observation): string => (string) $observation->id, $result->observations),
            'account_ids' => array_map(static fn (Account $account): int => (int) $account->id, $result->accounts),
            'person_ids' => array_map(static fn (Person $person): int => (int) $person->id, $result->people),
            'counts' => $result->counts,
            'replayed' => $result->replayed,
        ]], 202);
    }

    private function assertOwner(User $user, Workspace $workspace): void
    {
        abort_unless($workspace->isOwner($user), 403);
    }

    private function sourceModel(Workspace $workspace, string $id): ProspectSource
    {
        return ProspectSource::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey(strtolower($id))
            ->firstOrFail();
    }

    /** @return array<string, mixed>|null */
    private function runRow(Workspace $workspace, string $run): ?array
    {
        if (! ProspectSourceSurface::available() || ! Schema::hasTable('source_runs')) {
            return null;
        }

        $row = SourceRun::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey(strtolower($run))
            ->first();

        return $row === null ? null : $this->runPayload($row);
    }

    private function runModel(Workspace $workspace, string $run): SourceRun
    {
        return SourceRun::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey(strtolower($run))
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function runPayload(mixed $run): array
    {
        if (is_array($run)) {
            return $run;
        }

        if (is_object($run)) {
            $payload = [];
            foreach (['id', 'state', 'received', 'valid', 'accepted', 'created', 'linked', 'duplicate', 'conflicted', 'suppressed', 'rejected', 'expired', 'created_at', 'finished_at'] as $key) {
                $value = $run->{$key} ?? null;
                $payload[$key] = $value instanceof \BackedEnum ? $value->value : $value;
            }

            return $payload;
        }

        return ['state' => 'queued'];
    }

    /** @return list<IngestionObservation> */
    private function buildObservations(mixed $input): array
    {
        if (! is_array($input)) {
            throw ValidationException::withMessages([
                'rows' => 'Observation rows must be an array.',
            ]);
        }

        $observations = [];
        foreach ($input as $rawRow) {
            $row = $this->stringKeyedRow($rawRow);
            $values = $row['observed_values'] ?? null;
            if (! is_array($values)) {
                $values = $row['values'] ?? null;
            }
            $observedValues = is_array($values) ? $this->stringKeyedRow($values) : $row;

            $observations[] = new IngestionObservation(
                entityType: (string) ($row['entity_type'] ?? 'person'),
                observedValues: $observedValues,
                rowNumber: isset($row['row_number']) ? (int) $row['row_number'] : null,
                inputKey: isset($row['input_key']) ? (string) $row['input_key'] : null,
                observationKey: isset($row['observation_key']) ? (string) $row['observation_key'] : null,
                rawInputHash: isset($row['raw_input_hash']) ? (string) $row['raw_input_hash'] : null,
                providerRecordId: isset($row['provider_record_id']) ? (string) $row['provider_record_id'] : null,
                providerSnapshotId: isset($row['provider_snapshot_id']) ? (string) $row['provider_snapshot_id'] : null,
                canonicalUrl: isset($row['canonical_url']) ? (string) $row['canonical_url'] : null,
                providerObservedAt: isset($row['provider_observed_at']) ? (string) $row['provider_observed_at'] : null,
            );
        }

        return $observations;
    }

    /** @return array<string, mixed> */
    private function stringKeyedRow(mixed $row): array
    {
        if (! is_array($row)) {
            throw ValidationException::withMessages([
                'rows' => 'Each observation row must be an object.',
            ]);
        }

        $result = [];
        foreach ($row as $key => $value) {
            if (! is_string($key)) {
                throw ValidationException::withMessages([
                    'rows' => 'Each observation row must be an object.',
                ]);
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
