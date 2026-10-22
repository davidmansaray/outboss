<?php

namespace App\Http\Controllers;

use App\Domain\People\Support\ProspectSourceSurface;
use App\Domain\Prospecting\DTOs\IngestionBatch;
use App\Domain\Prospecting\DTOs\IngestionObservation;
use App\Domain\Prospecting\Exceptions\SourcePolicyViolation;
use App\Domain\Prospecting\Services\BuildProspectSourceExport;
use App\Domain\Prospecting\Services\CommitSourceRun;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Domain\Prospecting\Services\DeleteProspectSourceData as DeleteProspectSourceDataService;
use App\Domain\Prospecting\Services\ProspectIngestionService;
use App\Domain\Prospecting\Services\StartSourceRun;
use App\Domain\Prospecting\Services\UpdateProspectSource;
use App\Domain\Workspaces\Services\AuditWorkspaceDataExport;
use App\Domain\Workspaces\Support\CsvExport;
use App\Jobs\Prospecting\DeleteProspectSourceData as DeleteProspectSourceDataJob;
use App\Models\ProspectSource;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Session-facing source management. Source aggregate mutations are delegated
 * to Prospecting services; this controller only resolves the current
 * workspace, validates bounded transport input, and chooses the response.
 */
class ProspectSourceController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace();

        return Inertia::render('prospect-sources/Index', [
            'sources' => ProspectSourceSurface::list($workspace),
        ]);
    }

    public function store(Request $request, CreateProspectSource $create): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'string', 'max:60'],
            'configuration' => ['sometimes', 'array'],
            'policy' => ['sometimes', 'array'],
        ]);

        $create->handle(
            workspace: $workspace,
            kind: $data['kind'],
            displayName: $data['display_name'],
            configuration: $data['configuration'] ?? [],
            policy: $data['policy'] ?? [],
            actor: $request->user(),
            origin: 'user',
        );

        return back()->with('success', 'Source created.');
    }

    public function show(Request $request, string $source): Response
    {
        $workspace = $request->user()->currentWorkspace();
        $detail = ProspectSourceSurface::detail($workspace, $source);

        abort_if($detail === null, 404);

        return Inertia::render('prospect-sources/Show', [
            'source' => $detail,
        ]);
    }

    public function update(Request $request, string $source, UpdateProspectSource $update): RedirectResponse
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
            origin: 'user',
        );

        return back()->with('success', 'Source saved.');
    }

    public function archive(Request $request, string $source, UpdateProspectSource $update): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $sourceModel = $this->sourceModel($workspace, $source);

        $update->archive($sourceModel, $request->user());

        return back()->with('success', 'Source archived.');
    }

    public function runs(Request $request, string $source): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        abort_if(ProspectSourceSurface::find($workspace, $source) === null, 404);

        return response()->json(['data' => ProspectSourceSurface::runs($workspace, $source)->values()->all()]);
    }

    public function startRun(Request $request, string $source, StartSourceRun $start, ProspectIngestionService $ingestion): RedirectResponse|JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $sourceModel = $this->sourceModel($workspace, $source);
        $data = $request->validate([
            'idempotency_key' => ['sometimes', 'string', 'max:160'],
            'input_reference' => ['sometimes', 'array', 'max:20'],
            'input' => ['sometimes', 'array', 'max:1000'],
        ]);
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = 'session:'.hash('sha256', json_encode([
                'workspace_id' => $workspace->id,
                'source_id' => $sourceModel->id,
                'input_reference' => $data['input_reference'] ?? [],
                'input' => $data['input'] ?? [],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        if (($data['input'] ?? []) !== []) {
            $result = $ingestion->handle(new IngestionBatch(
                workspace: $workspace,
                source: $sourceModel,
                sourceVersion: null,
                idempotencyKey: $idempotencyKey,
                observations: $this->observations($data['input']),
                inputReference: $data['input_reference'] ?? ['transport' => 'session_source_run'],
                actor: $request->user(),
                initiatorType: 'user',
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
                initiatorType: 'user',
            );
        }

        if ($this->isInertia($request)) {
            return back()->with('success', 'Source run started.');
        }

        return response()->json(['data' => $this->runPayload($run)], 202);
    }

    public function run(Request $request, string $source, string $run): Response|JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $sourceRow = ProspectSourceSurface::find($workspace, $source);
        abort_if($sourceRow === null, 404);

        $runRow = ProspectSourceSurface::runs($workspace, $source)
            ->first(fn (array $candidate): bool => $candidate['id'] === strtolower($run));
        abort_if($runRow === null, 404);

        $observations = ProspectSourceSurface::observations(
            $workspace,
            $run,
            max(1, $request->integer('page', 1)),
            min(100, max(1, $request->integer('per_page', 50))),
        );

        if ($request->wantsJson()) {
            return response()->json(['data' => [...$runRow, 'observations' => $observations]]);
        }

        return Inertia::render('prospect-sources/Run', [
            'source' => ProspectSourceSurface::detail($workspace, $source),
            'run' => [...$runRow, 'observations' => $observations],
        ]);
    }

    public function commit(Request $request, string $source, string $run, CommitSourceRun $commit): RedirectResponse|JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $runModel = $this->runModel($workspace, $source, $run);
        $result = $commit->handle($runModel);

        if ($this->isInertia($request)) {
            return back()->with('success', 'Source run committed.');
        }

        return response()->json(['data' => $this->runPayload($result)], 202);
    }

    public function retry(Request $request, string $source, string $run, StartSourceRun $start): RedirectResponse|JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $sourceModel = $this->sourceModel($workspace, $source);
        $runModel = $this->runModel($workspace, $source, $run);
        $result = $start->handle(
            workspace: $workspace,
            source: $sourceModel,
            version: $runModel->version()->firstOrFail(),
            idempotencyKey: 'retry:'.$run,
            input: $this->inputReference($runModel->input_reference),
            actor: $request->user(),
            initiatorType: 'user',
        );
        if ((string) $result->id !== (string) $runModel->id && $result->retry_of_run_id === null) {
            $result->forceFill(['retry_of_run_id' => $runModel->id])->save();
        }

        if ($this->isInertia($request)) {
            return back()->with('success', 'Source run retry started.');
        }

        return response()->json(['data' => $this->runPayload($result)], 202);
    }

    public function errors(Request $request, string $source, string $run): StreamedResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $sourceRow = ProspectSourceSurface::find($workspace, $source);
        abort_if($sourceRow === null, 404);
        abort_if(
            ProspectSourceSurface::runs($workspace, $source)
                ->first(fn (array $candidate): bool => $candidate['id'] === strtolower($run)) === null,
            404,
        );
        $rows = ProspectSourceSurface::observations($workspace, $run, 1, 100);
        $errors = collect($rows['data'])->filter(fn (array $row): bool => in_array(
            $row['disposition'],
            ['rejected', 'conflicted', 'suppressed', 'expired'],
            true,
        ));

        return $this->noStore(CsvExport::stream('source-errors.csv', [
            'row_number', 'entity_type', 'disposition', 'match_method', 'safe_error_code',
        ], $errors->map(fn (array $row): array => [
            $row['row_number'],
            $row['entity_type'],
            $row['disposition'],
            $row['match_method'],
            $row['safe_error_code'],
        ])));
    }

    public function export(
        Request $request,
        string $source,
        BuildProspectSourceExport $export,
        AuditWorkspaceDataExport $audit,
    ): StreamedResponse {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $sourceModel = $this->sourceModel($workspace, $source);
        try {
            $export->assertExportable($sourceModel);
        } catch (SourcePolicyViolation $exception) {
            abort(403, $exception->getMessage());
        }
        $audit->record($workspace, $request->user(), 'prospect_source', $sourceModel);

        return $this->noStore(CsvExport::stream(
            'prospect-source.csv',
            $export->headers(),
            $export->rows($sourceModel),
        ));
    }

    public function deleteData(Request $request, string $source, DeleteProspectSourceDataService $delete): RedirectResponse|JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->assertOwner($request->user(), $workspace);
        $sourceModel = $this->sourceModel($workspace, $source);
        $data = $request->validate([
            'delete_canonical' => ['sometimes', 'boolean'],
        ]);

        try {
            $delete->prepareForDeletion($sourceModel, (bool) ($data['delete_canonical'] ?? false));
        } catch (SourcePolicyViolation $exception) {
            abort(403, $exception->getMessage());
        }

        DeleteProspectSourceDataJob::dispatch(
            workspaceId: (int) $workspace->id,
            sourceId: (string) $sourceModel->id,
            deleteCanonical: (bool) ($data['delete_canonical'] ?? false),
        );

        if ($this->isInertia($request)) {
            return back()->with('success', 'Source data deletion queued.');
        }

        return response()->json(['data' => ['queued' => true]], 202);
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

    private function runModel(Workspace $workspace, string $source, string $run): SourceRun
    {
        $this->sourceModel($workspace, $source);

        return SourceRun::query()
            ->where('workspace_id', $workspace->id)
            ->where('prospect_source_id', strtolower($source))
            ->whereKey(strtolower($run))
            ->firstOrFail();
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
            );
        }

        return $observations;
    }

    /** @return array<string, mixed> */
    private function inputReference(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $reference = [];
        foreach ($input as $key => $value) {
            if (is_string($key)) {
                $reference[$key] = $value;
            }
        }

        return $reference;
    }

    /** @return array<string, mixed> */
    private function runPayload(mixed $run): array
    {
        if (is_array($run)) {
            return $run;
        }

        if (is_object($run)) {
            $state = $run->state ?? 'queued';
            if ($state instanceof \BackedEnum) {
                $state = $state->value;
            }

            return [
                'id' => (string) ($run->id ?? ''),
                'state' => (string) $state,
                'received' => (int) ($run->received ?? 0),
                'valid' => (int) ($run->valid ?? 0),
                'accepted' => (int) ($run->accepted ?? 0),
                'created' => (int) ($run->created ?? 0),
                'linked' => (int) ($run->linked ?? 0),
                'duplicate' => (int) ($run->duplicate ?? 0),
                'conflicted' => (int) ($run->conflicted ?? 0),
                'suppressed' => (int) ($run->suppressed ?? 0),
                'rejected' => (int) ($run->rejected ?? 0),
                'expired' => (int) ($run->expired ?? 0),
                'created_at' => $this->date($run->created_at ?? null),
                'finished_at' => $this->date($run->finished_at ?? null),
            ];
        }

        return ['state' => 'queued'];
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : (is_string($value) ? $value : null);
    }

    private function isInertia(Request $request): bool
    {
        return $request->header('X-Inertia') === 'true';
    }

    private function noStore(StreamedResponse $response): StreamedResponse
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
