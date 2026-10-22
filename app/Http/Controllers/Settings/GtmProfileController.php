<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Gtm\Services\ExportGtmProfile;
use App\Domain\Gtm\Services\RefreshGtmProfileResearch;
use App\Domain\Gtm\Services\ResolveCurrentGtmProfileVersion;
use App\Domain\Gtm\Services\ResolveGtmProfileReviewItem;
use App\Domain\Gtm\Services\UpdateGtmProfile;
use App\Domain\Gtm\Services\UpdateGtmProfileWebsite;
use App\Domain\Gtm\Support\CanonicalGtmProfilePayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ResolveGtmProfileReviewRequest;
use App\Http\Requests\Settings\StoreGtmProfileResearchRequest;
use App\Http\Requests\Settings\UpdateGtmProfileSectionRequest;
use App\Http\Requests\Settings\UpdateGtmProfileWebsiteRequest;
use App\Models\GtmProfile;
use App\Models\GtmProfileResearchRun;
use App\Models\GtmProfileReviewItem;
use App\Models\GtmProfileSource;
use App\Models\GtmProfileVersion;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class GtmProfileController extends Controller
{
    public function __construct(
        private readonly ResolveCurrentGtmProfileVersion $versions,
        private readonly UpdateGtmProfile $updates,
        private readonly UpdateGtmProfileWebsite $websites,
        private readonly RefreshGtmProfileResearch $research,
        private readonly ResolveGtmProfileReviewItem $reviews,
        private readonly ExportGtmProfile $exports,
    ) {}

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $workspace = $user->currentWorkspace();
        abort_unless($workspace->membershipFor($user) !== null, 403);

        $profile = $workspace->gtmProfile()->firstOrFail();
        $reference = $this->versions->resolve($profile);
        $currentVersion = GtmProfileVersion::query()
            ->whereKey($reference->versionId)
            ->where('gtm_profile_id', $profile->id)
            ->firstOrFail();

        $payload = (array) $currentVersion->payload;
        $visiblePayload = CanonicalGtmProfilePayload::visible($payload);
        $sourceIds = $this->sourceIds($visiblePayload);
        $sourceCount = GtmProfileSource::query()
            ->where('gtm_profile_id', $profile->id)
            ->when($sourceIds === [], fn ($query) => $query->whereRaw('1 = 0'))
            ->when($sourceIds !== [], fn ($query) => $query->whereIn('id', $sourceIds))
            ->count();

        $reviewItems = GtmProfileReviewItem::query()
            ->where('gtm_profile_id', $profile->id)
            ->where('status', 'open')
            ->orderBy('created_at')
            ->get()
            ->map(fn (GtmProfileReviewItem $item): array => $this->reviewItem($item))
            ->values()
            ->all();

        return Inertia::render('gtm-profile/Show', [
            'profile' => [
                'uuid' => (string) $profile->id,
                'lock_version' => (int) $profile->lock_version,
                'payload' => $visiblePayload,
                'current_version_id' => (string) $currentVersion->id,
                'current_version' => $this->version($currentVersion, true),
            ],
            'researchState' => $this->researchState($profile, $visiblePayload, $sourceCount),
            'reviewItems' => $reviewItems,
            'activeOffersCount' => $workspace->offers()
                ->where('status', 'active')
                ->count(),
            'isOwner' => $workspace->isOwner($user),
        ]);
    }

    public function updateSection(UpdateGtmProfileSectionRequest $request, string $section): JsonResponse
    {
        $user = $this->user($request);
        $profile = $this->profileFor($user);
        $data = $request->validated();
        /** @var array<string, mixed> $payload */
        $payload = $data['payload'];

        try {
            $updated = $this->updates->handle(
                $profile,
                $user,
                $section,
                $payload,
                (int) $data['expected_lock_version'],
                (string) $data['client_operation_id'],
            );
        } catch (RuntimeException $exception) {
            return $this->conflict($exception, $profile, $section);
        }

        return $this->mutationResponse($updated);
    }

    public function updateWebsite(UpdateGtmProfileWebsiteRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $profile = $this->profileFor($user);
        $data = $request->validated();

        try {
            $updated = $this->websites->handle(
                $profile,
                $user,
                $data['website_url'] ?? null,
                (int) $data['expected_lock_version'],
                (string) $data['client_operation_id'],
            );
        } catch (RuntimeException $exception) {
            return $this->conflict($exception, $profile, 'company');
        }

        $run = $this->currentRun($updated);

        return $this->mutationResponse($updated, $run);
    }

    public function research(StoreGtmProfileResearchRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $profile = $this->profileFor($user);
        $data = $request->validated();

        try {
            $run = $this->research->handle(
                $profile,
                $user,
                (string) $data['action'],
                (string) $data['client_operation_id'],
            );
        } catch (RuntimeException $exception) {
            return $this->conflict($exception, $profile);
        }

        return $this->mutationResponse($profile->fresh('currentVersion'), $run);
    }

    public function resolveReview(ResolveGtmProfileReviewRequest $request, string $item): JsonResponse
    {
        $user = $this->user($request);
        $profile = $this->profileFor($user);
        $review = $profile->reviewItems()->whereKey($item)->firstOrFail();
        $data = $request->validated();
        $editedValue = $data['edited_value'] ?? $data['value'] ?? null;
        if ($editedValue !== null && ! is_array($editedValue)) {
            $editedValue = ['value' => $editedValue];
        }

        try {
            $updated = $this->reviews->handle(
                $review,
                $user,
                (string) $data['action'],
                $editedValue,
                (int) $data['expected_lock_version'],
                (string) $data['client_operation_id'],
            );
        } catch (RuntimeException $exception) {
            return $this->conflict($exception, $profile);
        }

        return $this->mutationResponse($updated);
    }

    public function sources(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $profile = $this->profileFor($user);
        $version = $this->currentVersion($profile);
        $sourceIds = $this->sourceIds(
            CanonicalGtmProfilePayload::visible((array) $version->payload),
        );
        $sources = GtmProfileSource::query()
            ->where('gtm_profile_id', $profile->id)
            ->when($sourceIds === [], fn ($query) => $query->whereRaw('1 = 0'))
            ->when($sourceIds !== [], fn ($query) => $query->whereIn('id', $sourceIds))
            ->orderByDesc('observed_at')
            ->get()
            ->map(fn (GtmProfileSource $source): array => $this->source($source))
            ->values()
            ->all();

        return response()->json([
            'sources' => $sources,
            'source_count' => count($sources),
            'current_version_id' => (string) $version->id,
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $profile = $this->profileFor($user);
        $currentVersion = $this->currentVersion($profile);
        $perPage = min(max($request->integer('per_page', 20), 1), 50);
        $paginator = GtmProfileVersion::query()
            ->where('gtm_profile_id', $profile->id)
            ->orderByDesc('version')
            ->cursorPaginate($perPage, ['*'], 'cursor');
        $history = $paginator->getCollection()
            ->map(fn (GtmProfileVersion $version): array => $this->version(
                $version,
                $version->is($currentVersion),
            ))
            ->values()
            ->all();

        return response()->json([
            'history' => $history,
            'current_version_id' => (string) $currentVersion->id,
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function exportProfile(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $profile = $this->profileFor($user);
        $payload = $this->exports->handle($profile, $user);

        return response()->json($payload, 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment; filename="gtm-profile.json"',
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function profileFor(User $user): GtmProfile
    {
        return $user->currentWorkspace()->gtmProfile()->firstOrFail();
    }

    private function currentVersion(GtmProfile $profile): GtmProfileVersion
    {
        $reference = $this->versions->resolve($profile);

        return GtmProfileVersion::query()
            ->where('gtm_profile_id', $profile->id)
            ->whereKey($reference->versionId)
            ->firstOrFail();
    }

    private function currentRun(GtmProfile $profile): ?GtmProfileResearchRun
    {
        $runId = $profile->getRawOriginal('current_research_run_id');
        if (! is_string($runId) || $runId === '') {
            return null;
        }

        return GtmProfileResearchRun::query()
            ->whereKey($runId)
            ->where('gtm_profile_id', $profile->id)
            ->first();
    }

    private function mutationResponse(
        GtmProfile $profile,
        ?GtmProfileResearchRun $run = null,
    ): JsonResponse {
        $profile->load('currentVersion');
        $currentVersion = $profile->currentVersion;

        return response()->json([
            'profile' => [
                'uuid' => (string) $profile->id,
                'lock_version' => (int) $profile->lock_version,
                'payload' => $currentVersion !== null
                    ? CanonicalGtmProfilePayload::visible((array) $currentVersion->payload)
                    : [],
                'current_version_id' => $currentVersion?->id,
                'current_version' => $currentVersion !== null
                    ? $this->version($currentVersion, true)
                    : null,
            ],
            'version' => $currentVersion !== null ? $this->version($currentVersion, true) : null,
            'lock_version' => (int) $profile->lock_version,
            'current_version_id' => $currentVersion?->id,
            'run_uuid' => $run?->id,
            'run' => $run !== null ? [
                'uuid' => (string) $run->id,
                'state' => $this->enum($run->state),
                'website_url_snapshot' => $run->website_url_snapshot,
            ] : null,
        ]);
    }

    private function conflict(
        RuntimeException $exception,
        ?GtmProfile $profile = null,
        ?string $section = null,
    ): JsonResponse {
        $code = $exception->getMessage();
        $message = match ($code) {
            'stale_version' => 'The profile changed. Reload and try again.',
            'operation_replay_mismatch' => 'This client operation ID was already used for a different request.',
            'research_in_progress' => 'Research is already queued or running.',
            'research_retry_not_available' => 'Research cannot be retried from its current state.',
            'review_item_already_resolved' => 'This review question has already been resolved.',
            default => 'The profile could not be updated. Reload and try again.',
        };
        $current = $profile?->fresh('currentVersion');
        $details = [
            'code' => $code,
            'message' => $message,
        ];
        if ($section !== null) {
            $details['section'] = $section;
        }
        if ($current instanceof GtmProfile) {
            $details['lock_version'] = (int) $current->lock_version;
            $details['current_version_id'] = $current->current_version_id;

            if ($section !== null && $current->currentVersion !== null) {
                $visible = CanonicalGtmProfilePayload::visible(
                    (array) $current->currentVersion->payload,
                );
                $currentSection = $visible[$section] ?? null;

                if (is_array($currentSection)) {
                    $details['current_section'] = $currentSection;
                }
            }
        }

        return response()->json([
            'error' => [
                'type' => 'conflict',
                ...$details,
            ],
            'errors' => $details,
            'conflict' => $details,
        ], 409);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function researchState(GtmProfile $profile, array $payload, int $sourceCount): array
    {
        $rawState = (string) $profile->getRawOriginal('research_state');
        $knownStates = [
            'not_started',
            'awaiting_provider',
            'awaiting_budget',
            'queued',
            'running',
            'ready',
            'failed',
            'cancelled',
        ];
        $state = in_array($rawState, $knownStates, true) ? $rawState : 'not_started';
        $rawAwaitingReason = $profile->getRawOriginal('awaiting_reason');
        $awaitingReason = is_string($rawAwaitingReason) && in_array($rawAwaitingReason, ['provider', 'budget'], true)
            ? $rawAwaitingReason
            : null;
        $safeErrorCode = $profile->getRawOriginal('safe_error_code');
        $safeErrorCodes = config('gtm.safe_error_codes', []);
        $safeErrorCode = is_string($safeErrorCode)
            && is_array($safeErrorCodes)
            && in_array($safeErrorCode, $safeErrorCodes, true)
            ? $safeErrorCode
            : null;
        $lastResearchedAt = $profile->getAttribute('last_researched_at');
        $staleAfter = (int) config('gtm.research.stale_after_seconds', 30 * 24 * 60 * 60);
        $stale = $state === 'ready'
            && $lastResearchedAt instanceof DateTimeInterface
            && $lastResearchedAt < now()->subSeconds($staleAfter);

        return [
            'state' => $state,
            'awaiting_reason' => $awaitingReason,
            'safe_error_code' => $safeErrorCode,
            'last_researched_at' => $this->date($lastResearchedAt),
            'last_failed_at' => $this->date($profile->getAttribute('last_research_failed_at')),
            'source_count' => $sourceCount,
            'website_url' => $this->website($payload),
            'stale' => $stale,
            'run_uuid' => $profile->getRawOriginal('current_research_run_id'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function website(array $payload): ?string
    {
        $website = data_get($payload, 'company.website_url.value');

        return is_string($website) && $website !== '' ? $website : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function sourceIds(array $payload): array
    {
        $ids = [];
        $visit = function (mixed $value) use (&$visit, &$ids): void {
            if (! is_array($value)) {
                return;
            }

            $sourceIds = $value['source_ids'] ?? null;
            if (is_array($sourceIds)) {
                foreach ($sourceIds as $sourceId) {
                    if (is_string($sourceId) && preg_match('/^[0-9a-fA-F-]{36}$/', $sourceId) === 1) {
                        $ids[] = strtolower($sourceId);
                    }
                }
            }

            foreach ($value as $child) {
                $visit($child);
            }
        };
        $visit($payload);

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, mixed>
     */
    private function version(GtmProfileVersion $version, bool $current): array
    {
        return [
            'uuid' => (string) $version->id,
            'version' => (int) $version->version,
            'schema_version' => (int) $version->schema_version,
            'content_hash' => (string) $version->content_hash,
            'origin' => $this->enum($version->origin),
            'created_at' => $this->date($version->created_at),
            'actor' => [
                'type' => $this->enum($version->actor_type),
                'name' => $version->actor_display_name,
            ],
            'is_current' => $current,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function source(GtmProfileSource $source): array
    {
        return [
            'uuid' => (string) $source->id,
            'source_type' => (string) $source->source_type,
            'canonical_url' => (string) $source->canonical_url,
            'title' => $source->title,
            'excerpt' => $source->excerpt,
            'observed_at' => $this->date($source->observed_at),
            'published_at' => $this->date($source->published_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewItem(GtmProfileReviewItem $item): array
    {
        return [
            'uuid' => (string) $item->id,
            'field_path' => (string) $item->field_path,
            'type' => $this->enum($item->type),
            'proposed_value' => $item->proposed_value,
            'source_ids' => $item->source_ids ?? [],
            'status' => $this->enum($item->status),
        ];
    }

    private function enum(mixed $value): ?string
    {
        return $value instanceof BackedEnum
            ? (string) $value->value
            : (is_string($value) ? $value : null);
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : null;
    }
}
