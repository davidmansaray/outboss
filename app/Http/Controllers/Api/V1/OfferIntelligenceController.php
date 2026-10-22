<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Services\ArchiveAsset;
use App\Domain\Gtm\Services\ChangeOfferStatus;
use App\Domain\Gtm\Services\ConfigureOperationalOffer;
use App\Domain\Gtm\Services\CreateAsset;
use App\Domain\Gtm\Services\CreateFulfilmentCapability;
use App\Domain\Gtm\Services\CreateOffer;
use App\Domain\Gtm\Services\EvaluateOfferReadiness;
use App\Domain\Gtm\Services\ReviewOfferVersion;
use App\Domain\Gtm\Services\VersionAsset;
use App\Http\Controllers\Api\V1\Concerns\HandlesS2Transport;
use App\Http\Requests\ChangeOfferStatusRequest;
use App\Http\Requests\ConfigureOperationalOfferRequest;
use App\Http\Requests\ReviewOfferVersionRequest;
use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\StoreOfferRequest;
use App\Http\Requests\VersionAssetRequest;
use App\Http\Resources\Api\V1\AssetResource;
use App\Http\Resources\Api\V1\OfferReadinessResource;
use App\Http\Resources\Api\V1\OfferResource;
use App\Models\Asset;
use App\Models\FulfilmentCapability;
use App\Models\FulfilmentRequest;
use App\Models\Offer;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class OfferIntelligenceController
{
    use HandlesS2Transport;

    public function store(StoreOfferRequest $request, CreateOffer $create): JsonResponse|OfferResource
    {
        $data = $request->validated();
        $key = trim((string) ($data['client_operation_id'] ?? $this->idempotencyKey($request, true)));

        try {
            $offer = $create->handle(
                $request->user()->currentWorkspace(),
                $request->user(),
                array_intersect_key($data, array_flip([
                    'name',
                    'promise',
                    'target_audience',
                    'desired_outcome',
                    'call_to_action',
                    'notes',
                ])),
                $key,
            );
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return (new OfferResource($offer->load(['currentVersion', 'currentReadinessEvaluation'])))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $workspace = $request->user()->currentWorkspace();
        $query = Offer::query()
            ->where('workspace_id', $workspace->id)
            ->with(['currentVersion', 'currentReadinessEvaluation'])
            ->latest('updated_at');
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return OfferResource::collection($query->paginate($this->perPage($request)));
    }

    public function show(Request $request, string $offer): OfferResource
    {
        return new OfferResource($this->offer($request, $offer, true));
    }

    public function readiness(Request $request, string $offer, EvaluateOfferReadiness $readiness): OfferReadinessResource
    {
        $model = $this->offer($request, $offer);
        $evaluation = $readiness->handle($model, 'manual');

        return new OfferReadinessResource($evaluation);
    }

    public function configure(
        ConfigureOperationalOfferRequest $request,
        string $offer,
        ConfigureOperationalOffer $configure,
    ): JsonResponse|OfferResource {
        $model = $this->offer($request, $offer);
        $this->authorise($request, 'update', $model);
        $data = $request->validated();
        $payload = $data['payload'] ?? array_diff_key($data, array_flip([
            'expected_lock_version', 'client_operation_id', 'operation_id',
        ]));
        $payload = $this->boundedPayload($payload, 'payload');
        $operationId = trim((string) ($data['client_operation_id'] ?? $data['operation_id'] ?? $this->idempotencyKey($request, true)));
        if ($operationId === '') {
            throw ValidationException::withMessages(['client_operation_id' => 'A bounded operation id is required.']);
        }

        try {
            $updated = $configure->handle(
                $model,
                $request->user(),
                $payload,
                (int) $data['expected_lock_version'],
                $operationId,
            );
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return new OfferResource($updated->load(['currentVersion', 'currentReadinessEvaluation']));
    }

    public function review(
        ReviewOfferVersionRequest $request,
        string $offer,
        ReviewOfferVersion $review,
    ): JsonResponse|OfferResource {
        $model = $this->offer($request, $offer);
        $this->authorise($request, 'changeStatus', $model);
        $data = $request->validated();

        try {
            $updated = $review->handle($model, $request->user(), (string) $data['action'], $data['reason'] ?? null);
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return new OfferResource($updated->load(['currentVersion', 'currentReadinessEvaluation', 'versionReviewEvents']));
    }

    public function activate(
        ChangeOfferStatusRequest $request,
        string $offer,
        ChangeOfferStatus $change,
    ): JsonResponse|OfferResource {
        $model = $this->offer($request, $offer);
        $this->authorise($request, 'changeStatus', $model);
        $data = $request->validated();
        if (($data['status'] ?? null) !== OfferStatus::Active->value) {
            throw ValidationException::withMessages(['status' => 'This endpoint only activates an approved Offer.']);
        }
        $key = trim((string) ($data['client_operation_id'] ?? $this->idempotencyKey($request, true)));

        try {
            $updated = $change->handle(
                $model,
                $request->user(),
                OfferStatus::Active,
                (int) $data['expected_lock_version'],
                $key,
            );
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return new OfferResource($updated->load(['currentVersion', 'currentReadinessEvaluation']));
    }

    public function assets(Request $request): AnonymousResourceCollection
    {
        $workspace = $request->user()->currentWorkspace();
        $query = Asset::query()
            ->where('workspace_id', $workspace->id)
            ->with('currentVersion')
            ->latest('updated_at');
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return AssetResource::collection($query->paginate($this->perPage($request)));
    }

    public function showAsset(Request $request, string $asset): AssetResource
    {
        return new AssetResource($this->assetModel($request, $asset, true));
    }

    public function storeAsset(StoreAssetRequest $request, CreateAsset $create): JsonResponse|AssetResource
    {
        $workspace = $request->user()->currentWorkspace();
        $data = $request->validated();
        $key = $this->assetOperationId($request, $data, true);
        unset($data['client_operation_id'], $data['operation_id'], $data['idempotency_key']);
        try {
            $asset = $create->handle($workspace, $request->user(), $data, $key);
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return (new AssetResource($asset->load('currentVersion')))->response()->setStatusCode(201);
    }

    public function versionAsset(
        VersionAssetRequest $request,
        string $asset,
        VersionAsset $version,
    ): JsonResponse|AssetResource {
        $model = $this->assetModel($request, $asset);
        $data = $request->validated();
        $expected = (int) $data['expected_lock_version'];
        $key = $this->assetOperationId($request, $data, true);
        unset(
            $data['expected_lock_version'],
            $data['client_operation_id'],
            $data['operation_id'],
            $data['idempotency_key'],
        );
        $data = $this->boundedPayload($data, 'asset');

        try {
            $updated = $version->handle($model, $request->user(), $data, $expected, $key);
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return new AssetResource($updated->load('currentVersion'));
    }

    public function archiveAsset(
        Request $request,
        string $asset,
        ArchiveAsset $archive,
    ): JsonResponse|AssetResource {
        $model = $this->assetModel($request, $asset);
        $data = $request->validate([
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'client_operation_id' => ['sometimes', 'nullable', 'string', 'max:160'],
            'operation_id' => ['sometimes', 'nullable', 'string', 'max:160'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:160'],
        ]);
        $expected = (int) $data['expected_lock_version'];
        $key = $this->assetOperationId($request, $data, true);

        try {
            $updated = $archive->handle($model, $request->user(), $expected, $key);
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return new AssetResource($updated->load('currentVersion'));
    }

    public function storeCapability(Request $request, CreateFulfilmentCapability $create): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        abort_unless($workspace->isOwner($request->user()), 403);
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:160'],
            'mode' => ['required', 'string', 'in:external_destination,existing_asset,human_assisted'],
            'status' => ['sometimes', 'string', 'in:draft,active'],
            'configuration' => ['sometimes', 'array', 'max:20'],
            'owner_user_id' => ['nullable', 'integer'],
            'response_sla_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'capacity_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'capacity_window' => ['nullable', 'string', 'in:day,week,month'],
            'health_state' => ['sometimes', 'string', 'in:unknown,healthy'],
        ]);
        try {
            $capability = $create->handle($workspace, $request->user(), $data, $this->idempotencyKey($request, true));
        } catch (Throwable $exception) {
            return $this->s2Failure($exception);
        }

        return response()->json(['data' => $this->capabilityPayload($capability)], 201);
    }

    public function fulfilmentRequests(Request $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $query = FulfilmentRequest::query()
            ->where('workspace_id', $workspace->id)
            ->latest('created_at');
        if ($request->filled('account_id')) {
            $query->where('account_id', $request->integer('account_id'));
        }
        if ($request->filled('offer_id')) {
            $query->where('offer_id', (string) $request->query('offer_id'));
        }

        $rows = [];
        foreach ($query->limit($this->perPage($request))->get() as $model) {
            $dueAt = $model->getAttribute('due_at');
            $createdAt = $model->getAttribute('created_at');
            $rows[] = [
                'id' => (string) $model->getKey(),
                'offer_id' => (string) $model->getAttribute('offer_id'),
                'offer_version_id' => (string) $model->getAttribute('offer_version_id'),
                'conversion_event_id' => (string) $model->getAttribute('conversion_event_id'),
                'account_id' => (int) $model->getAttribute('account_id'),
                'fulfilment_capability_id' => (string) $model->getAttribute('fulfilment_capability_id'),
                'state' => (string) $model->getAttribute('state'),
                'due_at' => $dueAt instanceof DateTimeInterface ? $dueAt->format(DATE_ATOM) : null,
                'created_at' => $createdAt instanceof DateTimeInterface ? $createdAt->format(DATE_ATOM) : null,
            ];
        }

        return response()->json(['data' => $rows]);
    }

    private function offer(Request $request, string $id, bool $withHistory = false): Offer
    {
        $query = Offer::query()
            ->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey(strtolower($id))
            ->with(['currentVersion', 'currentReadinessEvaluation']);
        if ($withHistory) {
            $query->with(['readinessEvaluations', 'versionReviewEvents']);
        }

        return $query->firstOrFail();
    }

    private function assetModel(Request $request, string $id, bool $withHistory = false): Asset
    {
        $query = Asset::query()
            ->where('workspace_id', $request->user()->currentWorkspace()->id)
            ->whereKey(strtolower($id))
            ->with('currentVersion');
        if ($withHistory) {
            $query->with('versions');
        }

        return $query->firstOrFail();
    }

    /** @param array<string,mixed> $data */
    private function assetOperationId(Request $request, array $data, bool $required): ?string
    {
        foreach (['client_operation_id', 'operation_id', 'idempotency_key'] as $field) {
            $value = $data[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $this->idempotencyKey($request, $required);
    }

    private function authorise(Request $request, string $ability, Offer $offer): void
    {
        Gate::forUser($request->user())->authorize($ability, $offer);
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, $request->integer('per_page', 20)));
    }

    /** @return array<string,mixed> */
    private function capabilityPayload(FulfilmentCapability $capability): array
    {
        $mode = $capability->getAttribute('mode');

        return [
            'id' => (string) $capability->getKey(),
            'display_name' => (string) $capability->getAttribute('display_name'),
            'mode' => $mode instanceof BackedEnum ? (string) $mode->value : (string) $mode,
            'status' => (string) $capability->getAttribute('status'),
            'owner_user_id' => $capability->getAttribute('owner_user_id') === null ? null : (int) $capability->getAttribute('owner_user_id'),
            'response_sla_hours' => $capability->getAttribute('response_sla_hours'),
            'capacity_limit' => $capability->getAttribute('capacity_limit'),
            'capacity_window' => $capability->getAttribute('capacity_window'),
            'health_state' => (string) $capability->getAttribute('health_state'),
        ];
    }
}
