<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Support\GtmResearchOutputValidator;
use App\Models\GtmProfile;
use App\Models\GtmProfileResearchRun;
use App\Models\GtmProfileSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Persist only bounded source metadata from a completed research phase. */
final class PersistGtmProfileResearchSources
{
    public function __construct(private readonly GtmResearchOutputValidator $validator) {}

    /**
     * @param  list<array<string, mixed>>  $sourceSpecs
     * @return list<string>
     */
    public function handle(
        GtmProfile $profile,
        GtmProfileResearchRun $run,
        array $sourceSpecs,
        ?string $extractOperationId = null,
        ?string $searchOperationId = null,
    ): array {
        $sources = $this->validator->validateSources($sourceSpecs);

        return DB::transaction(function () use ($profile, $run, $sources, $extractOperationId, $searchOperationId): array {
            $lockedProfile = GtmProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
            $lockedRun = GtmProfileResearchRun::query()
                ->whereKey($run->id)
                ->where('gtm_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();
            if ($lockedProfile === null || $lockedRun === null
                || (string) $lockedProfile->current_research_run_id !== (string) $lockedRun->id) {
                return [];
            }

            $ids = [];
            foreach ($sources as $source) {
                $contentHash = hash('sha256', $source['url'].'|'.($source['excerpt'] ?? ''));
                $operationId = $source['source_type'] === 'search' ? $searchOperationId : $extractOperationId;
                $observation = GtmProfileSource::query()->firstOrCreate([
                    'gtm_profile_id' => $lockedProfile->id,
                    'source_type' => $source['source_type'],
                    'content_hash' => $contentHash,
                    'research_run_id' => $lockedRun->id,
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
        }, 3);
    }
}
