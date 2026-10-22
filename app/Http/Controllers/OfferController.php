<?php

namespace App\Http\Controllers;

use App\Domain\Gtm\Enums\OfferStatus;
use App\Domain\Gtm\Services\ChangeOfferStatus;
use App\Domain\Gtm\Services\CreateOffer;
use App\Domain\Gtm\Services\DeleteOffer;
use App\Domain\Gtm\Services\UpdateOffer;
use App\Http\Requests\ChangeOfferStatusRequest;
use App\Http\Requests\StoreOfferRequest;
use App\Http\Requests\UpdateOfferRequest;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\User;
use App\Models\Workspace;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class OfferController extends Controller
{
    private const INDEX_PER_PAGE = 25;

    private const INDEX_MAX_PER_PAGE = 50;

    public function index(Request $request): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace();

        $perPage = min(
            max(1, $request->integer('per_page', self::INDEX_PER_PAGE)),
            self::INDEX_MAX_PER_PAGE,
        );
        $filter = $this->indexFilter($request);
        $requestedOffer = $this->requestedOverlayOffer($request, $workspace);
        $offerQuery = $workspace->offers();

        if ($filter instanceof OfferStatus) {
            $offerQuery->where('status', $filter->value);
        }

        $paginator = $offerQuery
            ->with('currentVersion')
            ->withCount('versions')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], 'cursor');
        $offers = $paginator->getCollection()
            ->map(fn (Offer $offer): array => $this->presentOffer($offer))
            ->values()
            ->all();

        if (
            $requestedOffer instanceof Offer
            && ! in_array((string) $requestedOffer->getKey(), array_column($offers, 'uuid'), true)
        ) {
            $offers[] = $this->presentOffer($requestedOffer);
        }
        $pagination = [
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
            'per_page' => $paginator->perPage(),
        ];
        $counts = $this->offerCounts($workspace->id);

        if ($request->expectsJson()) {
            return response()->json([
                'offers' => $offers,
                'pagination' => $pagination,
                'counts' => $counts,
                'filter' => $filter instanceof OfferStatus ? $filter->value : 'all',
            ]);
        }

        return Inertia::render('offers/Index', [
            'offers' => $offers,
            'offerPagination' => $pagination,
            'offerCounts' => $counts,
            'offerFilter' => $filter instanceof OfferStatus ? $filter->value : 'all',
            'isOwner' => $workspace->isOwner($user),
            'error' => null,
        ]);
    }

    public function store(StoreOfferRequest $request, CreateOffer $create): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        try {
            $offer = $create->handle(
                $user->currentWorkspace(),
                $user,
                $this->payload($data),
                $this->operationId($data),
            );
        } catch (RuntimeException $exception) {
            return $this->mutationError($request, $exception, 'Offer could not be created.');
        }

        return $this->mutationResponse($request, $offer, 201);
    }

    public function update(
        UpdateOfferRequest $request,
        string $offerId,
        UpdateOffer $update,
    ): JsonResponse|RedirectResponse {
        $offer = $this->scopedOffer($request, $offerId);
        $this->authorize('update', $offer);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        try {
            $updated = $update->handle(
                $offer,
                $user,
                $this->payload($data, $this->currentPayload($offer)),
                (int) $data['expected_lock_version'],
                $this->operationId($data),
            );
        } catch (RuntimeException $exception) {
            return $this->mutationError($request, $exception, 'Offer could not be saved.', $offer);
        }

        return $this->mutationResponse($request, $updated);
    }

    public function status(
        ChangeOfferStatusRequest $request,
        string $offerId,
        ChangeOfferStatus $change,
    ): JsonResponse|RedirectResponse {
        $offer = $this->scopedOffer($request, $offerId);
        $this->authorize('changeStatus', $offer);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        try {
            $updated = $change->handle(
                $offer,
                $user,
                OfferStatus::from((string) $data['status']),
                (int) $data['expected_lock_version'],
                $this->operationId($data),
            );
        } catch (RuntimeException $exception) {
            return $this->mutationError($request, $exception, 'Offer status could not be changed.', $offer);
        }

        return $this->mutationResponse($request, $updated);
    }

    public function history(Request $request, string $offerId): JsonResponse
    {
        $offer = $this->scopedOffer($request, $offerId);
        $this->authorize('history', $offer);
        $offer->load('currentVersion')->loadCount('versions');
        $perPage = min(
            max(1, $request->integer('per_page', (int) config('gtm.history.per_page', 20))),
            (int) config('gtm.history.max_per_page', 50),
        );
        $history = $offer->versions()
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], 'cursor');

        return response()->json([
            'offer' => $this->presentOffer($offer),
            'history' => $history->getCollection()
                ->map(fn (OfferVersion $version): array => $this->presentVersion($version))
                ->values()
                ->all(),
            'pagination' => [
                'next_cursor' => $history->nextCursor()?->encode(),
                'has_more' => $history->hasMorePages(),
                'per_page' => $history->perPage(),
            ],
        ]);
    }

    public function destroy(
        Request $request,
        string $offerId,
        DeleteOffer $delete,
    ): JsonResponse|RedirectResponse {
        $offer = $this->scopedOffer($request, $offerId);
        $this->authorize('delete', $offer);
        /** @var User $user */
        $user = $request->user();

        try {
            $delete->handle($offer, $user);
        } catch (RuntimeException $exception) {
            return $this->mutationError($request, $exception, 'Draft Offer could not be deleted.', $offer);
        }

        if ($request->expectsJson()) {
            return response()->json(['deleted' => true]);
        }

        return redirect()->route('offers.index');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function payload(array $data, array $fallback = []): array
    {
        $payload = [
            'name' => (string) ($data['name'] ?? $fallback['name'] ?? ''),
            'promise' => (string) ($data['promise'] ?? $fallback['promise'] ?? ''),
            'target_audience' => (string) ($data['target_audience'] ?? $fallback['target_audience'] ?? ''),
            'desired_outcome' => (string) ($data['desired_outcome'] ?? $fallback['desired_outcome'] ?? ''),
            'call_to_action' => (string) ($data['call_to_action'] ?? $fallback['call_to_action'] ?? ''),
            'notes' => (string) ($data['notes'] ?? $fallback['notes'] ?? ''),
        ];

        // The compact editor changes only the six legacy prose fields. Once
        // an Offer has an operational V2 contract, preserve that exact
        // contract rather than silently downgrading it during a prose edit.
        if (($fallback['schema_version'] ?? null) === 2) {
            foreach (['schema_version', 'proposition', 'requested_commitment', 'cta', 'fulfilment', 'eligibility', 'commercial_authority', 'approved_claims', 'prohibited_claims', 'expires_at', 'agent_parameter_policy'] as $field) {
                if (array_key_exists($field, $fallback)) {
                    $payload[$field] = $fallback[$field];
                }
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function currentPayload(Offer $offer): array
    {
        $offer->loadMissing('currentVersion');

        return is_array($offer->currentVersion?->payload) ? $offer->currentVersion->payload : [];
    }

    /** @param array<string, mixed> $data */
    private function operationId(array $data): ?string
    {
        $value = $data['client_operation_id'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function scopedOffer(Request $request, string $offerId): Offer
    {
        /** @var User $user */
        $user = $request->user();

        return $user->currentWorkspace()->offers()->whereKey($offerId)->firstOrFail();
    }

    private function indexFilter(Request $request): ?OfferStatus
    {
        $value = $request->query('status');

        if ($value === null || $value === '' || $value === 'all') {
            return null;
        }

        abort_unless(is_string($value), 404);
        $status = OfferStatus::tryFrom($value);
        abort_if($status === null, 404);

        return $status;
    }

    private function requestedOverlayOffer(Request $request, Workspace $workspace): ?Offer
    {
        $panel = $request->query('panel');
        $offerId = $request->query('offer');

        if ($panel === null && $offerId === null) {
            return null;
        }

        abort_unless(is_string($panel) && in_array($panel, ['edit', 'history'], true), 404);

        if ($offerId === null && $panel === 'edit') {
            return null;
        }

        abort_unless(is_string($offerId) && Str::isUuid($offerId), 404);

        return $workspace->offers()
            ->with('currentVersion')
            ->withCount('versions')
            ->whereKey($offerId)
            ->firstOrFail();
    }

    private function mutationResponse(
        Request $request,
        Offer $offer,
        int $status = 200,
    ): JsonResponse|RedirectResponse {
        $offer->loadMissing('currentVersion')->loadCount('versions');

        if ($request->expectsJson()) {
            $currentVersion = $offer->currentVersion;

            return response()->json([
                'offer' => $this->presentOffer($offer),
                'version' => $currentVersion !== null ? $this->presentVersion($currentVersion) : null,
                'lock_version' => (int) $offer->lock_version,
                'current_version_id' => $offer->current_version_id,
            ], $status);
        }

        return redirect()->route('offers.index');
    }

    private function mutationError(
        Request $request,
        RuntimeException $exception,
        string $fallback,
        ?Offer $offer = null,
    ): JsonResponse|RedirectResponse {
        $code = $exception->getMessage();
        $messages = [
            'stale_version' => 'This Offer changed elsewhere. Reload and try again.',
            'operation_replay_mismatch' => 'This client operation was already used for a different Offer change.',
            'offer_create_result_conflict' => 'This Offer creation could not be replayed safely. Try again.',
            'offer_operation_result_missing' => 'The saved Offer result could not be replayed safely. Reload and try again.',
            'offer_not_deletable' => 'Only unreferenced draft Offers can be deleted.',
            'offer_version_missing' => 'This Offer has no current version. Reload and try again.',
        ];
        $status = in_array($code, ['stale_version', 'operation_replay_mismatch', 'offer_create_result_conflict', 'offer_operation_result_missing'], true)
            ? 409
            : 422;
        $message = $messages[$code] ?? $fallback;
        $details = [
            'code' => $code,
            'message' => $message,
        ];
        $current = $offer?->fresh();
        if ($current instanceof Offer) {
            $current->load('currentVersion')->loadCount('versions');
            $details['lock_version'] = (int) $current->lock_version;
            $details['current_version_id'] = $current->current_version_id;
            $details['current_offer'] = $this->presentOffer($current);
        }

        if ($request->expectsJson()) {
            $legacyDetails = $details;
            unset($legacyDetails['current_offer']);

            return response()->json([
                'error' => [
                    'type' => 'conflict',
                    ...$details,
                ],
                'errors' => $legacyDetails,
                'conflict' => $legacyDetails,
            ], $status);
        }

        return back()->withErrors(['offer' => $message]);
    }

    /** @return array<string, mixed> */
    private function presentOffer(Offer $offer): array
    {
        $version = $offer->currentVersion;
        $payload = is_array($version?->payload) ? $version->payload : [];

        return [
            'uuid' => (string) $offer->getKey(),
            'name' => $this->payloadText($payload, 'name'),
            'promise' => $this->payloadText($payload, 'promise'),
            'target_audience' => $this->payloadText($payload, 'target_audience'),
            'desired_outcome' => $this->payloadText($payload, 'desired_outcome'),
            'call_to_action' => $this->payloadText($payload, 'call_to_action'),
            'notes' => $this->payloadText($payload, 'notes'),
            'status' => $this->statusValue($offer->status),
            'lock_version' => (int) $offer->lock_version,
            'current_version_id' => $offer->current_version_id !== null
                ? (string) $offer->current_version_id
                : null,
            'current_version' => $version !== null ? $this->presentVersion($version) : null,
            'version_count' => (int) $offer->getAttribute('versions_count'),
            'created_at' => $this->isoDate($offer->getAttribute('created_at')),
            'updated_at' => $this->isoDate($offer->getAttribute('updated_at')),
        ];
    }

    /** @return array<string, mixed> */
    private function presentVersion(OfferVersion $version): array
    {
        return [
            'uuid' => (string) $version->getKey(),
            'version' => (int) $version->version,
            'content_hash' => (string) $version->content_hash,
            'origin' => $this->enumValue($version->origin),
            'status_snapshot' => $this->statusValue($version->status_snapshot),
            'from_status' => $this->statusValue($version->from_status),
            'created_at' => $this->isoDate($version->getAttribute('created_at')),
            'actor' => [
                'type' => (string) $version->actor_type,
                'name' => $version->actor_display_name,
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function payloadText(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function statusValue(mixed $status): string
    {
        return $this->enumValue($status) ?? '';
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function isoDate(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : null;
    }

    /** @return array{all: int, draft: int, active: int, paused: int, archived: int} */
    private function offerCounts(int $workspaceId): array
    {
        $stored = Offer::query()
            ->where('workspace_id', $workspaceId)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $count = static fn (OfferStatus $status): int => (int) $stored->get($status->value, 0);
        $draft = $count(OfferStatus::Draft);
        $active = $count(OfferStatus::Active);
        $paused = $count(OfferStatus::Paused);
        $archived = $count(OfferStatus::Archived);

        return [
            'all' => $draft + $active + $paused + $archived,
            'draft' => $draft,
            'active' => $active,
            'paused' => $paused,
            'archived' => $archived,
        ];
    }
}
