<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Support\PublicWebsiteUrl;
use App\Domain\Workspaces\Services\WorkspaceAvatarManager;
use App\Models\GtmProfile;
use App\Models\GtmProfileClientOperation;
use App\Models\GtmProfileResearchRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Update the canonical research target and arrange the corresponding run.
 */
final class UpdateGtmProfileWebsite
{
    public function __construct(
        private readonly UpdateGtmProfile $profile,
        private readonly RefreshGtmProfileResearch $research,
        private readonly WorkspaceAvatarManager $avatars,
    ) {}

    public function handle(
        GtmProfile $profile,
        User $actor,
        ?string $websiteUrl,
        int $expectedLockVersion,
        ?string $clientOperationId = null,
    ): GtmProfile {
        try {
            $normalized = PublicWebsiteUrl::normalizeAndValidate($websiteUrl);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'website_url' => $exception->getMessage(),
            ]);
        }

        $clientOperationId ??= (string) Str::uuid();

        return DB::transaction(function () use (
            $profile,
            $actor,
            $normalized,
            $expectedLockVersion,
            $clientOperationId,
        ): GtmProfile {
            $before = $this->website(
                GtmProfile::query()->with('currentVersion')->findOrFail($profile->id),
            );
            $updated = $this->profile->updateWebsite(
                $profile,
                $actor,
                ['website_url' => $normalized],
                $expectedLockVersion,
                $clientOperationId,
            );
            $operation = GtmProfileClientOperation::query()
                ->where('gtm_profile_id', $profile->id)
                ->where('client_operation_id', $clientOperationId)
                ->lockForUpdate()
                ->firstOrFail();
            $metadata = $this->operationMetadata($operation);
            if (array_key_exists('website_research_run_uuid', $metadata)) {
                $runId = $metadata['website_research_run_uuid'];
                $updated->forceFill([
                    'current_research_run_id' => is_string($runId) && $runId !== '' ? $runId : null,
                ]);
                $updated->syncOriginalAttribute('current_research_run_id');

                return $updated;
            }

            $after = $this->website($updated);

            if ($after === null) {
                // Removing the target must retire every queued, running, or
                // awaiting run before clearing the profile pointer. This is
                // intentionally part of the same transaction as the website
                // version write so a stale worker cannot outlive the change.
                $this->research->cancelForWebsiteRemoval($updated, $actor);
                $this->clearResearchState($updated);
                $this->recordRunResult($operation, null);
                $updated->forceFill([
                    'current_research_run_id' => null,
                    'research_state' => 'not_started',
                    'awaiting_reason' => null,
                    'safe_error_code' => null,
                ]);
                $updated->syncOriginalAttribute('current_research_run_id');
                $this->refreshAvatarIfChanged($updated, $before, null);

                return $updated;
            }

            // A replay already has a matching run, while a genuinely new or
            // changed URL must supersede an older queued/running run.
            $run = $this->currentRunForWebsite($updated, $after)
                ?? $this->research->refreshForWebsiteChange($updated, $actor);
            $this->recordRunResult($operation, $run);
            $updated->forceFill(['current_research_run_id' => $run->id]);
            $updated->syncOriginalAttribute('current_research_run_id');
            $this->refreshAvatarIfChanged($updated, $before, $after);

            return $updated;
        }, 3);
    }

    public function update(
        GtmProfile $profile,
        User $actor,
        ?string $websiteUrl,
        int $expectedLockVersion,
        ?string $clientOperationId = null,
    ): GtmProfile {
        return $this->handle($profile, $actor, $websiteUrl, $expectedLockVersion, $clientOperationId);
    }

    private function website(GtmProfile $profile): ?string
    {
        $version = $profile->relationLoaded('currentVersion')
            ? $profile->currentVersion
            : $profile->currentVersion()->first();
        $website = data_get($version?->payload, 'company.website_url.value');

        return is_string($website) && $website !== '' ? $website : null;
    }

    private function refreshAvatarIfChanged(
        GtmProfile $profile,
        ?string $before,
        ?string $after,
    ): void {
        if ($before === $after) {
            return;
        }

        $workspace = $profile->workspace()->firstOrFail();
        $this->avatars->websiteChanged($workspace, $after);
    }

    private function currentRunForWebsite(GtmProfile $profile, string $website): ?GtmProfileResearchRun
    {
        $runId = $profile->getRawOriginal('current_research_run_id');
        if (! is_string($runId) || $runId === '') {
            return null;
        }

        $run = $profile->researchRuns()
            ->whereKey($runId)
            ->first();

        return $run !== null
            && (string) $run->website_url_snapshot === $website
            && in_array((string) $run->getRawOriginal('state'), [
                'queued',
                'running',
                'awaiting_provider',
                'awaiting_budget',
                'ready',
            ], true)
                ? $run
                : null;
    }

    private function recordRunResult(
        GtmProfileClientOperation $operation,
        ?GtmProfileResearchRun $run,
    ): void {
        $metadata = $this->operationMetadata($operation);
        $operation->forceFill([
            'response_metadata' => [
                ...$metadata,
                'website_research_run_uuid' => $run?->id,
            ],
        ])->save();
    }

    /** @return array<string, mixed> */
    private function operationMetadata(GtmProfileClientOperation $operation): array
    {
        $metadata = $operation->getAttribute('response_metadata');

        return is_array($metadata) ? $metadata : [];
    }

    private function clearResearchState(GtmProfile $profile): void
    {
        $profile->forceFill([
            'current_research_run_id' => null,
            'research_state' => 'not_started',
            'awaiting_reason' => null,
            'safe_error_code' => null,
        ])->save();
    }
}
