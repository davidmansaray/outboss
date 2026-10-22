<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Support\CanonicalGtmProfilePayload;
use App\Models\GtmProfile;
use App\Models\GtmProfileReviewItem;
use App\Models\GtmProfileSource;
use App\Models\GtmProfileVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** Build the owner-only, workspace-scoped JSON profile export. */
final class ExportGtmProfile
{
    public const MAX_VERSIONS = 25;

    public const MAX_SOURCES = 200;

    public const MAX_REVIEW_ITEMS = 200;

    /** @return array<string, mixed> */
    public function handle(GtmProfile $profile, User $actor): array
    {
        $workspace = $profile->workspace()->first();
        if ($workspace === null || ! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can export the GTM profile.');
        }

        $currentVersion = GtmProfileVersion::query()
            ->where('gtm_profile_id', $profile->id)
            ->whereKey($profile->current_version_id)
            ->firstOrFail();

        $versionQuery = GtmProfileVersion::query()
            ->where('gtm_profile_id', $profile->id);
        $versionCount = (clone $versionQuery)->count();
        $versions = $versionQuery
            ->orderByDesc('version')
            ->limit(self::MAX_VERSIONS)
            ->get()
            ->map(fn (GtmProfileVersion $version): array => $this->version($version, $version->is($currentVersion)))
            ->values()
            ->all();

        $sourceQuery = GtmProfileSource::query()
            ->where('gtm_profile_id', $profile->id);
        $sourceCount = (clone $sourceQuery)->count();
        $sources = $sourceQuery
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->limit(self::MAX_SOURCES)
            ->get()
            ->map(fn (GtmProfileSource $source): array => [
                'uuid' => (string) $source->id,
                'source_type' => (string) $source->source_type,
                'canonical_url' => (string) $source->canonical_url,
                'title' => $source->title,
                'excerpt' => $source->excerpt,
                'observed_at' => $this->date($source->observed_at),
                'published_at' => $this->date($source->published_at),
                'content_hash' => (string) $source->content_hash,
            ])
            ->values()
            ->all();

        $reviewItemQuery = GtmProfileReviewItem::query()
            ->where('gtm_profile_id', $profile->id);
        $reviewItemCount = (clone $reviewItemQuery)->count();
        $reviewItems = $reviewItemQuery
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_REVIEW_ITEMS)
            ->get()
            ->map(fn (GtmProfileReviewItem $item): array => [
                'uuid' => (string) $item->id,
                'field_path' => (string) $item->field_path,
                'type' => $this->enum($item->type),
                'proposed_value' => $item->proposed_value,
                'source_ids' => $item->source_ids ?? [],
                'proposed_value_hash' => $item->proposed_value_hash,
                'status' => $this->enum($item->status),
                'resolved_value' => $item->resolved_value,
                'resolved_version_id' => $item->resolved_version_id,
                'resolved_by_user_id' => $item->resolved_by_user_id,
                'resolved_at' => $this->date($item->resolved_at),
            ])
            ->values()
            ->all();

        return [
            'format' => 'outboss.gtm-profile',
            'schema_version' => GtmProfileVersion::SCHEMA_VERSION,
            'exported_at' => now()->toIso8601String(),
            'collections' => [
                'versions' => $this->collectionMetadata($versionCount, count($versions), self::MAX_VERSIONS),
                'sources' => $this->collectionMetadata($sourceCount, count($sources), self::MAX_SOURCES),
                'review_items' => $this->collectionMetadata($reviewItemCount, count($reviewItems), self::MAX_REVIEW_ITEMS),
            ],
            'workspace' => [
                'id' => (int) $workspace->id,
                'name' => (string) $workspace->name,
                'type' => (string) $workspace->type,
            ],
            'profile' => [
                'uuid' => (string) $profile->id,
                'lock_version' => (int) $profile->lock_version,
                'current_version_id' => (string) $currentVersion->id,
                'payload' => CanonicalGtmProfilePayload::visible((array) $currentVersion->payload),
            ],
            'versions' => $versions,
            'sources' => $sources,
            'review_items' => $reviewItems,
        ];
    }

    /** @return array<string, mixed> */
    public function export(GtmProfile $profile, User $actor): array
    {
        return $this->handle($profile, $actor);
    }

    /** @return array<string, mixed> */
    private function version(GtmProfileVersion $version, bool $current): array
    {
        return [
            'uuid' => (string) $version->id,
            'version' => (int) $version->version,
            'schema_version' => (int) $version->schema_version,
            'content_hash' => (string) $version->content_hash,
            'payload' => CanonicalGtmProfilePayload::visible((array) $version->payload),
            'origin' => $this->enum($version->origin),
            'actor' => [
                'type' => $this->enum($version->actor_type),
                'name' => $version->actor_display_name,
            ],
            'created_at' => $this->date($version->created_at),
            'is_current' => $current,
        ];
    }

    private function enum(mixed $value): ?string
    {
        return $value instanceof \BackedEnum
            ? (string) $value->value
            : (is_string($value) ? $value : null);
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(\DateTimeInterface::ATOM) : null;
    }

    /** @return array{total: int, included: int, limit: int, truncated: bool} */
    private function collectionMetadata(int $total, int $included, int $limit): array
    {
        return [
            'total' => $total,
            'included' => $included,
            'limit' => $limit,
            'truncated' => $total > $included,
        ];
    }
}
