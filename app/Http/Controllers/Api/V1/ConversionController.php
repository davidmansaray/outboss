<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Conversions\DTOs\ConversionIntakeResult;
use App\Domain\Conversions\Services\ActivateConversionDefinition;
use App\Domain\Conversions\Services\ArchiveConversionDefinition;
use App\Domain\Conversions\Services\CorrectConversionEvent;
use App\Domain\Conversions\Services\CreateConversionDefinition;
use App\Domain\Conversions\Services\IngestConversionEvent;
use App\Domain\Conversions\Services\ReverseConversionEvent;
use App\Domain\Conversions\Services\ReviewConversionEvent;
use App\Domain\Conversions\Services\VersionConversionDefinition;
use App\Http\Controllers\Api\V1\Concerns\HandlesS2Transport;
use App\Http\Requests\CorrectConversionEventRequest;
use App\Http\Requests\ReviewConversionEventRequest;
use App\Http\Requests\StoreConversionDefinitionRequest;
use App\Http\Requests\StoreConversionEventRequest;
use App\Http\Requests\VersionConversionDefinitionRequest;
use App\Http\Resources\Api\V1\ConversionDefinitionResource;
use App\Http\Resources\Api\V1\ConversionEventResource;
use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use App\Models\ConversionEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Throwable;

class ConversionController
{
    use HandlesS2Transport;

    public function definitions(Request $request): AnonymousResourceCollection
    {
        $workspace = $request->user()->currentWorkspace();
        $query = ConversionDefinition::query()->where('workspace_id', $workspace->id)->with('currentVersion')->orderBy('event_key');
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return ConversionDefinitionResource::collection($query->paginate($this->perPage($request)));
    }

    public function definition(Request $request, string $definition): ConversionDefinitionResource
    {
        $model = $this->definitionModel($request, $definition, withVersions: true);

        return new ConversionDefinitionResource($model);
    }

    public function storeDefinition(
        StoreConversionDefinitionRequest $request,
        CreateConversionDefinition $create,
    ): JsonResponse|ConversionDefinitionResource {
        $workspace = $request->user()->currentWorkspace();
        $data = $request->validated();
        $key = $this->idempotencyKey($request, true);
        unset($data['idempotency_key']);
        $data = $this->boundedPayload($data, 'definition');

        try {
            $definition = $create->handle($workspace, $request->user(), $data, $key);
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return (new ConversionDefinitionResource($definition->load(['currentVersion'])))->response()->setStatusCode(201);
    }

    public function versionDefinition(
        VersionConversionDefinitionRequest $request,
        string $definition,
        VersionConversionDefinition $version,
    ): JsonResponse|ConversionDefinitionResource {
        $model = $this->definitionModel($request, $definition);
        $data = $request->validated();
        $expected = (int) ($data['expected_lock_version'] ?? -1);
        if ($expected < 0) {
            throw ValidationException::withMessages(['expected_lock_version' => 'The current definition lock version is required.']);
        }
        $key = $this->idempotencyKey($request, true);
        unset($data['idempotency_key'], $data['expected_lock_version']);
        $data = $this->boundedPayload($data, 'definition');

        try {
            $updated = $version->handle($model, $request->user(), $data, $expected, $key);
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return new ConversionDefinitionResource($updated->load(['currentVersion']));
    }

    public function activateDefinition(Request $request, string $definition, ActivateConversionDefinition $activate): JsonResponse|ConversionDefinitionResource
    {
        $model = $this->definitionModel($request, $definition);
        [$expected, $key] = $this->mutationInputs($request);

        try {
            $updated = $activate->handle($model, $request->user(), $expected);
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return new ConversionDefinitionResource($updated->load(['currentVersion']));
    }

    public function archiveDefinition(Request $request, string $definition, ArchiveConversionDefinition $archive): JsonResponse|ConversionDefinitionResource
    {
        $model = $this->definitionModel($request, $definition);
        [$expected, $key] = $this->mutationInputs($request);

        try {
            $updated = $archive->handle($model, $request->user(), $expected);
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return new ConversionDefinitionResource($updated->load(['currentVersion']));
    }

    public function events(Request $request): AnonymousResourceCollection
    {
        $workspace = $request->user()->currentWorkspace();
        $query = ConversionEvent::query()->where('workspace_id', $workspace->id)->with(['matches', 'reviewEvents'])->latest('occurred_at');
        if ($request->filled('state')) {
            $query->where('state', (string) $request->query('state'));
        }
        if ($request->filled('account_id')) {
            $query->where('matched_account_id', (int) $request->integer('account_id'));
        }
        if ($request->filled('definition_id')) {
            $query->where('conversion_definition_id', (string) $request->query('definition_id'));
        }

        return ConversionEventResource::collection($query->paginate($this->perPage($request)));
    }

    public function event(Request $request, string $event): ConversionEventResource
    {
        $model = $this->eventModel($request, $event);

        return new ConversionEventResource($model);
    }

    public function storeEvent(
        StoreConversionEventRequest $request,
        IngestConversionEvent $ingest,
    ): JsonResponse|ConversionEventResource {
        $workspace = $request->user()->currentWorkspace();
        $data = $request->validated();
        $key = $this->idempotencyKey($request, true);
        $definition = $this->resolveDefinition($workspace->id, $data);
        $source = (string) ($data['source'] ?? $data['source_type'] ?? 'manual');
        unset(
            $data['definition_id'],
            $data['conversion_definition_id'],
            $data['definition_version_id'],
            $data['conversion_definition_version_id'],
            $data['source'],
            $data['source_type'],
            $data['idempotency_key'],
        );
        $data = $this->boundedPayload($data, 'event');

        try {
            $result = $ingest->handle(
                $workspace,
                $definition,
                $data,
                $source,
                null,
                $request->user(),
                $key,
            );
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return $this->intakeResponse($result);
    }

    public function reviewEvent(
        ReviewConversionEventRequest $request,
        string $event,
        ReviewConversionEvent $review,
    ): JsonResponse|ConversionEventResource {
        $model = $this->eventModel($request, $event);
        $data = $request->validated();

        try {
            $updated = $review->handle(
                $model,
                $request->user(),
                (string) $data['action'],
                isset($data['account_id']) ? (int) $data['account_id'] : null,
                $data['reason'] ?? null,
            );
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return new ConversionEventResource($updated->load(['matches', 'reviewEvents']));
    }

    public function correctEvent(
        CorrectConversionEventRequest $request,
        string $event,
        CorrectConversionEvent $correct,
    ): JsonResponse|ConversionEventResource {
        $model = $this->eventModel($request, $event);
        $data = $request->validated();
        $key = $this->idempotencyKey($request, false);
        $reason = $data['reason'] ?? null;
        unset($data['reason'], $data['idempotency_key']);
        $data = $this->boundedPayload($data, 'changes');

        try {
            $updated = $correct->handle($model, $request->user(), $data, $reason, $key);
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return new ConversionEventResource($updated->load(['matches', 'reviewEvents']));
    }

    public function reverseEvent(Request $request, string $event, ReverseConversionEvent $reverse): JsonResponse|ConversionEventResource
    {
        $model = $this->eventModel($request, $event);
        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'idempotency_key' => ['sometimes', 'string', 'max:160'],
        ]);
        $key = $this->idempotencyKey($request, false);

        try {
            $updated = $reverse->handle($model, $request->user(), $data['reason'] ?? null, $key);
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return new ConversionEventResource($updated->load(['matches', 'reviewEvents']));
    }

    private function definitionModel(Request $request, string $id, bool $withVersions = false): ConversionDefinition
    {
        $query = ConversionDefinition::query()->where('workspace_id', $request->user()->currentWorkspace()->id)->whereKey(strtolower($id));
        if ($withVersions) {
            $query->with(['currentVersion', 'versions']);
        } else {
            $query->with('currentVersion');
        }

        return $query->firstOrFail();
    }

    private function eventModel(Request $request, string $id): ConversionEvent
    {
        return ConversionEvent::query()
            ->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey(strtolower($id))
            ->with(['matches', 'reviewEvents'])
            ->firstOrFail();
    }

    /** @param array<string,mixed> $data */
    private function resolveDefinition(int|string $workspaceId, array $data): ConversionDefinition|ConversionDefinitionVersion
    {
        $definitionId = $data['definition_id']
            ?? $data['conversion_definition_id']
            ?? null;
        $versionId = $data['definition_version_id']
            ?? $data['conversion_definition_version_id']
            ?? null;
        if (is_string($versionId) && $versionId !== '') {
            return ConversionDefinitionVersion::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey(strtolower($versionId))
                ->firstOrFail();
        }
        if (! is_string($definitionId) || trim($definitionId) === '') {
            throw ValidationException::withMessages(['definition_id' => 'A Conversion Definition is required.']);
        }

        return ConversionDefinition::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey(strtolower($definitionId))
            ->firstOrFail();
    }

    /** @return array{0:int,1:?string} */
    private function mutationInputs(Request $request): array
    {
        $data = $request->validate([
            'expected_lock_version' => ['sometimes', 'integer', 'min:0'],
            'idempotency_key' => ['sometimes', 'string', 'max:160'],
        ]);
        $key = $this->idempotencyKey($request, true);
        if (! array_key_exists('expected_lock_version', $data)) {
            throw ValidationException::withMessages(['expected_lock_version' => 'The current definition lock version is required.']);
        }

        return [(int) $data['expected_lock_version'], $key];
    }

    private function intakeResponse(ConversionIntakeResult $result): JsonResponse
    {
        $event = $result->event;

        return response()->json([
            'data' => [
                ...$result->toArray(),
                'receipt_id' => (string) $result->receipt->id,
                'event' => $event === null ? null : (new ConversionEventResource($event->load(['matches', 'reviewEvents'])))->resolve(),
            ],
        ], $result->duplicate ? 200 : 201);
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, $request->integer('per_page', 20)));
    }
}
