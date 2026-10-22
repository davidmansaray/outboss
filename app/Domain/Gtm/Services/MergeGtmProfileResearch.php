<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Enums\GtmProfileResearchState;
use App\Domain\Gtm\Enums\GtmProfileReviewStatus;
use App\Domain\Gtm\Enums\GtmProfileReviewType;
use App\Domain\Gtm\Enums\GtmProfileVersionActor;
use App\Domain\Gtm\Enums\GtmProfileVersionOrigin;
use App\Domain\Gtm\Enums\GtmResearchRunState;
use App\Domain\Gtm\Support\CanonicalGtmProfilePayload;
use App\Domain\Gtm\Support\GtmProfilePayloadValidator;
use App\Domain\Gtm\Support\GtmResearchOutputValidator;
use App\Models\GtmProfile;
use App\Models\GtmProfileResearchRun;
use App\Models\GtmProfileReviewItem;
use App\Models\GtmProfileSource;
use App\Models\GtmProfileVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Apply one validated research result to the latest profile version.
 *
 * This method is the only place where system research is allowed to write a
 * profile. It locks the aggregate, reloads the latest owner-edited version,
 * preserves owner locks, and records disagreements as review items.
 */
final class MergeGtmProfileResearch
{
    /** @var list<string> */
    private const SCALAR_FIELDS = [
        'company.name',
        'company.category',
        'company.product_description',
        'company.target_problem',
        'company.primary_outcome',
        'market.target_segment',
        'market.timing_thesis',
        'messaging.brand_voice',
        'synthesis',
    ];

    /** @var list<string> */
    private const LIST_FIELDS = [
        'market.geographies',
        'market.company_traits',
        'market.excluded_segments',
        'buyers.target_roles',
        'buyers.buying_triggers',
        'buyers.blocking_roles',
        'messaging.proof_points',
        'messaging.prohibited_claims',
        'messaging.message_constraints',
        'qualification.evidence_requirements',
        'qualification.business_disqualifiers',
    ];

    /** @var list<string> */
    private const REQUIRED_FIELDS = [
        'company.name',
        'company.product_description',
        'company.target_problem',
        'market.target_segment',
        'buyers.target_roles',
    ];

    public function __construct(
        private readonly GtmResearchOutputValidator $outputValidator,
        private readonly GtmProfilePayloadValidator $payloadValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     * @param  list<array<string, mixed>>  $sourceSpecs
     * @param  array<string, list<int>>  $citationSourceIndexes
     */
    public function handle(
        GtmProfile $profile,
        GtmProfileResearchRun $run,
        array $output,
        array $sourceSpecs = [],
        ?string $extractOperationId = null,
        ?string $searchOperationId = null,
        array $citationSourceIndexes = [],
    ): GtmProfile {
        return $this->merge(
            $profile,
            $run,
            $output,
            $sourceSpecs,
            $extractOperationId,
            $searchOperationId,
            $citationSourceIndexes,
        );
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  list<array<string, mixed>>  $sourceSpecs
     * @param  array<string, list<int>>  $citationSourceIndexes
     */
    public function merge(
        GtmProfile $profile,
        GtmProfileResearchRun $run,
        array $output,
        array $sourceSpecs = [],
        ?string $extractOperationId = null,
        ?string $searchOperationId = null,
        array $citationSourceIndexes = [],
    ): GtmProfile {
        $normalizedOutput = $this->outputValidator->validate($output);
        $normalizedSources = $sourceSpecs === []
            ? $this->outputValidator->validateSources($normalizedOutput['sources'] ?? [])
            : $this->outputValidator->validateSources($sourceSpecs);

        return DB::transaction(function () use (
            $profile,
            $run,
            $normalizedOutput,
            $normalizedSources,
            $extractOperationId,
            $searchOperationId,
            $citationSourceIndexes,
        ): GtmProfile {
            $lockedProfile = GtmProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $lockedRun = GtmProfileResearchRun::query()
                ->whereKey($run->id)
                ->where('gtm_profile_id', $lockedProfile->id)
                ->lockForUpdate()
                ->firstOrFail();

            // A refresh or website change owns the current pointer. An old
            // job may finish its provider work, but it cannot write anything.
            if ((string) $lockedProfile->current_research_run_id !== (string) $lockedRun->id) {
                return $lockedProfile->fresh(['currentVersion']);
            }
            if (in_array($lockedRun->state->value, [
                GtmResearchRunState::Ready->value,
                GtmResearchRunState::Cancelled->value,
            ], true)) {
                return $lockedProfile->fresh(['currentVersion']);
            }

            $currentVersion = $this->currentVersion($lockedProfile);
            $sourceIds = $this->persistSources(
                $lockedProfile,
                $lockedRun,
                $normalizedSources,
                $extractOperationId,
                $searchOperationId,
            );
            $sourceIdsByField = $this->sourceIdsByField($citationSourceIndexes, $sourceIds);
            $candidate = $currentVersion->payload;
            $conflicts = [];

            $this->mergeScalar(
                $candidate,
                $normalizedOutput,
                'synthesis',
                $sourceIdsByField['synthesis'] ?? [],
                $conflicts,
            );
            foreach (['company', 'market', 'buyers', 'messaging', 'qualification'] as $section) {
                $sectionOutput = $normalizedOutput[$section] ?? null;
                if (! is_array($sectionOutput)) {
                    continue;
                }
                foreach ($sectionOutput as $field => $value) {
                    $path = $section.'.'.$field;
                    if (in_array($path, self::SCALAR_FIELDS, true)) {
                        $this->mergeScalar(
                            $candidate,
                            $normalizedOutput,
                            $path,
                            $sourceIdsByField[$path] ?? [],
                            $conflicts,
                        );
                    } elseif (in_array($path, self::LIST_FIELDS, true)) {
                        $this->mergeList(
                            $candidate,
                            $normalizedOutput,
                            $path,
                            $sourceIdsByField[$path] ?? [],
                            $conflicts,
                        );
                    }
                }
            }

            $candidate = $this->payloadValidator->validate($candidate);
            $this->recordConflicts($lockedProfile, $conflicts);
            $this->recordMissingFields($lockedProfile, $candidate);

            $version = $this->writeVersion($lockedProfile, $candidate);
            $lockedProfile->forceFill([
                'current_version_id' => $version->id,
                'research_state' => GtmProfileResearchState::Ready,
                'awaiting_reason' => null,
                'last_researched_at' => now(),
                'last_research_failed_at' => null,
                'safe_error_code' => null,
                'last_researched_website_url' => $lockedRun->website_url_snapshot,
                'lock_version' => (int) $lockedProfile->lock_version + 1,
            ])->save();
            $lockedRun->forceFill([
                'state' => GtmResearchRunState::Ready,
                'awaiting_reason' => null,
                'safe_failure_code' => null,
                'finished_at' => now(),
                'failed_at' => null,
            ])->save();

            return $lockedProfile->fresh(['currentVersion']);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $output
     * @param  list<string>  $sourceIds
     * @param  list<array{path: string, value: string, source_ids: list<string>}>  $conflicts
     */
    private function mergeScalar(
        array &$candidate,
        array $output,
        string $path,
        array $sourceIds,
        array &$conflicts,
    ): void {
        $value = data_get($output, $path);
        if (! is_string($value) || trim($value) === '') {
            return;
        }
        $existing = data_get($candidate, $path);
        if (is_array($existing) && ($existing['owner_locked'] ?? false) === true) {
            $existingValue = $existing['value'] ?? null;
            if ($this->canonicalValue($existingValue) !== $this->canonicalValue($value)) {
                $conflicts[] = ['path' => $path, 'value' => $value, 'source_ids' => $sourceIds];
            }

            return;
        }

        data_set($candidate, $path, [
            'value' => $value,
            'provenance' => 'ai_inferred',
            'source_ids' => $sourceIds,
            'owner_locked' => false,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $output
     * @param  list<string>  $sourceIds
     * @param  list<array{path: string, value: string, source_ids: list<string>}>  $conflicts
     */
    private function mergeList(
        array &$candidate,
        array $output,
        string $path,
        array $sourceIds,
        array &$conflicts,
    ): void {
        $value = data_get($output, $path);
        if (! is_array($value)) {
            return;
        }
        $existing = data_get($candidate, $path);
        $existingItems = is_array($existing) && array_is_list($existing) ? $existing : [];
        $protected = [];
        $ownerLocked = [];
        $tombstoned = [];
        $result = [];
        foreach ($existingItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $canonical = $this->canonicalValue($item['value'] ?? null);
            if (($item['owner_locked'] ?? false) === true) {
                $ownerLocked[$canonical] = true;
            }
            if (($item['tombstoned'] ?? false) === true) {
                $tombstoned[$canonical] = true;
            }
            if (($item['owner_locked'] ?? false) === true || ($item['tombstoned'] ?? false) === true) {
                $result[] = $item;
                $protected[$canonical] = true;
            }
        }

        $researchItems = [];
        $researchCanonicals = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                continue;
            }
            $item = trim(str_replace(["\r\n", "\r"], "\n", $item));
            $canonical = $this->canonicalValue($item);
            if (isset($researchCanonicals[$canonical])) {
                continue;
            }
            $researchCanonicals[$canonical] = true;
            $researchItems[] = ['value' => $item, 'canonical' => $canonical];
        }

        // A repeated field is matched by canonical value, so a new research
        // item is normally an additive fact. When research replaces the
        // entire owner-locked set with a disjoint set, however, the result is
        // a material disagreement and must be reviewable. Tombstones are
        // stricter: an exact match is always a conflict because the owner
        // explicitly deleted that value and research may not resurrect it.
        $ownerDisagreement = $ownerLocked !== []
            && array_intersect(array_keys($ownerLocked), array_keys($researchCanonicals)) === [];

        foreach ($researchItems as $researchItem) {
            $item = $researchItem['value'];
            $canonical = $researchItem['canonical'];
            if (isset($tombstoned[$canonical])) {
                $conflicts[] = ['path' => $path, 'value' => $item, 'source_ids' => $sourceIds];

                continue;
            }
            if (isset($protected[$canonical])) {
                continue;
            }
            if ($ownerDisagreement) {
                $conflicts[] = ['path' => $path, 'value' => $item, 'source_ids' => $sourceIds];
            }
            $result[] = [
                'id' => $this->stableItemId($path, $item),
                'value' => $item,
                'provenance' => 'ai_inferred',
                'source_ids' => $sourceIds,
                'owner_locked' => false,
                'updated_at' => now()->toIso8601String(),
                'tombstoned' => false,
            ];
            $protected[$canonical] = true;
        }

        data_set($candidate, $path, $result);
    }

    /**
     * @param  list<array{path: string, value: string, source_ids: list<string>}>  $conflicts
     */
    private function recordConflicts(GtmProfile $profile, array $conflicts): void
    {
        foreach ($conflicts as $conflict) {
            $this->recordReviewItem(
                $profile,
                $conflict['path'],
                GtmProfileReviewType::Conflict,
                ['value' => $conflict['value']],
                $conflict['source_ids'],
            );
        }
    }

    /**
     * @param  array<string, list<int>>  $citationSourceIndexes
     * @param  list<string>  $sourceIds
     * @return array<string, list<string>>
     */
    private function sourceIdsByField(array $citationSourceIndexes, array $sourceIds): array
    {
        $mapped = [];
        foreach ($citationSourceIndexes as $path => $indexes) {
            $mapped[$path] = [];
            foreach ($indexes as $index) {
                if (! array_key_exists($index, $sourceIds)) {
                    throw new RuntimeException('A research citation references an unavailable source.');
                }
                $mapped[$path][] = $sourceIds[$index];
            }
            $mapped[$path] = array_values(array_unique($mapped[$path]));
        }

        return $mapped;
    }

    /** @param array<string, mixed> $payload */
    private function recordMissingFields(GtmProfile $profile, array $payload): void
    {
        foreach (self::REQUIRED_FIELDS as $path) {
            $value = data_get($payload, $path);
            if (is_array($value) && array_is_list($value)) {
                $missing = count(array_filter(
                    $value,
                    static fn (mixed $item): bool => is_array($item)
                        && ($item['tombstoned'] ?? false) !== true
                        && trim((string) ($item['value'] ?? '')) !== '',
                )) === 0;
            } else {
                if (! is_array($value)) {
                    $missing = true;
                } else {
                    $fieldValue = $value['value'] ?? null;
                    $missing = ! is_string($fieldValue) || trim($fieldValue) === '';
                }
            }
            if ($missing) {
                $this->recordReviewItem($profile, $path, GtmProfileReviewType::Missing, null, []);
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $proposedValue
     * @param  list<string>  $sourceIds
     */
    private function recordReviewItem(
        GtmProfile $profile,
        string $path,
        GtmProfileReviewType $type,
        ?array $proposedValue,
        array $sourceIds,
    ): void {
        $hash = hash('sha256', json_encode([
            'path' => $path,
            'type' => $type->value,
            'value' => $proposedValue,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $exists = GtmProfileReviewItem::query()
            ->where('gtm_profile_id', $profile->id)
            ->where('field_path', $path)
            ->where('proposed_value_hash', $hash)
            ->exists();
        if ($exists || GtmProfileReviewItem::query()
            ->where('gtm_profile_id', $profile->id)
            ->where('status', GtmProfileReviewStatus::Open)
            ->count() >= (int) config('gtm.limits.open_review_items_max', 20)) {
            return;
        }

        GtmProfileReviewItem::query()->create([
            'id' => (string) Str::uuid(),
            'gtm_profile_id' => $profile->id,
            'field_path' => $path,
            'type' => $type,
            'proposed_value' => $proposedValue,
            'source_ids' => array_values(array_unique($sourceIds)),
            'proposed_value_hash' => $hash,
            'status' => GtmProfileReviewStatus::Open,
            'resolved_value' => null,
            'resolved_version_id' => null,
            'resolved_by_user_id' => null,
            'resolved_at' => null,
        ]);
    }

    /**
     * @param  list<array{url: string, title: string|null, excerpt: string|null, source_type: string, published_at: string|null}>  $sourceSpecs
     * @return list<string>
     */
    private function persistSources(
        GtmProfile $profile,
        GtmProfileResearchRun $run,
        array $sourceSpecs,
        ?string $extractOperationId,
        ?string $searchOperationId,
    ): array {
        $ids = [];
        foreach ($sourceSpecs as $source) {
            $contentHash = hash('sha256', $source['url'].'|'.($source['excerpt'] ?? ''));
            $operationId = $source['source_type'] === 'search' ? $searchOperationId : $extractOperationId;
            $observation = GtmProfileSource::query()->firstOrCreate([
                'gtm_profile_id' => $profile->id,
                'source_type' => $source['source_type'],
                'content_hash' => $contentHash,
                'research_run_id' => $run->id,
            ], [
                'id' => (string) Str::uuid(),
                'canonical_url' => $source['url'],
                'title' => $source['title'],
                'observed_at' => now(),
                'published_at' => $source['published_at'],
                'excerpt' => $source['excerpt'],
                'content_hash' => $contentHash,
                'excerpt_hash' => $source['excerpt'] === null ? null : hash('sha256', $source['excerpt']),
                'provider_operation_id' => $operationId,
            ]);
            $ids[] = (string) $observation->id;
        }

        return array_values(array_unique(array_slice($ids, 0, (int) config('gtm.limits.current_version_sources_max', 100))));
    }

    private function currentVersion(GtmProfile $profile): GtmProfileVersion
    {
        $version = GtmProfileVersion::query()
            ->where('gtm_profile_id', $profile->id)
            ->whereKey($profile->current_version_id)
            ->lockForUpdate()
            ->first();
        if ($version === null) {
            throw new RuntimeException('profile_version_missing');
        }

        return $version;
    }

    /** @param array<string, mixed> $payload */
    private function writeVersion(
        GtmProfile $profile,
        array $payload,
    ): GtmProfileVersion {
        $latest = GtmProfileVersion::query()
            ->where('gtm_profile_id', $profile->id)
            ->orderByDesc('version')
            ->lockForUpdate()
            ->first();
        $version = GtmProfileVersion::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $profile->workspace_id,
            'gtm_profile_id' => $profile->id,
            'version' => ($latest === null ? 0 : (int) $latest->version) + 1,
            'schema_version' => GtmProfileVersion::SCHEMA_VERSION,
            'payload' => $payload,
            'content_hash' => CanonicalGtmProfilePayload::hash($payload),
            'actor_type' => GtmProfileVersionActor::SystemResearch,
            'actor_user_id' => null,
            'actor_display_name' => 'Outboss research',
            'origin' => GtmProfileVersionOrigin::ResearchMerge,
            'created_at' => now(),
        ]);

        return $version;
    }

    private function canonicalValue(mixed $value): string
    {
        if (is_string($value)) {
            return mb_strtolower((string) preg_replace('/\s+/', ' ', trim($value)));
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    private function stableItemId(string $path, string $value): string
    {
        $hex = substr(hash('sha256', 'gtm-profile-item|'.$path.'|'.$this->canonicalValue($value)), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
