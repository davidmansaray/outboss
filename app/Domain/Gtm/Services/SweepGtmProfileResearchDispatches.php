<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\Enums\GtmProfileResearchState;
use App\Domain\Gtm\Enums\GtmResearchRunState;
use App\Models\GtmProfile;
use App\Models\GtmProfileResearchDispatch;
use App\Models\GtmProfileResearchRun;
use Illuminate\Support\Facades\DB;

/** Repair lost dispatch leases/rows, then publish the durable queue source. */
final class SweepGtmProfileResearchDispatches
{
    public function __construct(
        private readonly PublishGtmProfileResearchDispatches $publisher,
        private readonly RunGtmProfileResearch $research,
        private readonly GtmResearchPhaseService $phases,
        private readonly FinalizeGtmResearchBudgetRuns $budgetFinalizer,
    ) {}

    public function handle(?int $limit = null, ?int $workspaceId = null): int
    {
        $repaired = DB::transaction(function () use ($workspaceId): int {
            $reclaimedQuery = GtmProfileResearchDispatch::query()
                ->where('status', 'leased')
                ->whereNotNull('lease_expires_at')
                ->where('lease_expires_at', '<=', now());
            if ($workspaceId !== null) {
                $reclaimedQuery->whereHas('profile', fn ($query) => $query->where('workspace_id', $workspaceId));
            }
            $reclaimed = $reclaimedQuery->update([
                'status' => 'pending',
                'available_at' => now(),
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

            return (int) $reclaimed;
        }, 3);

        $repaired += $this->repairProfiles($workspaceId);
        $repaired += $this->budgetFinalizer->handle($limit, $workspaceId);

        return $repaired + $this->publisher->handle($limit);
    }

    private function repairProfiles(?int $workspaceId): int
    {
        $repaired = 0;
        GtmProfile::query()
            ->whereIn('research_state', [
                GtmProfileResearchState::Queued,
                GtmProfileResearchState::Running,
                GtmProfileResearchState::AwaitingProvider,
                GtmProfileResearchState::AwaitingBudget,
            ])
            ->whereNotNull('current_research_run_id')
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->orderBy('id')
            ->each(function (GtmProfile $profile) use (&$repaired): void {
                $run = GtmProfileResearchRun::query()
                    ->whereKey($profile->current_research_run_id)
                    ->where('gtm_profile_id', $profile->id)
                    ->first();
                if ($run === null) {
                    return;
                }

                $state = (string) $run->getRawOriginal('state');
                if ($state === GtmResearchRunState::Queued->value) {
                    $repaired += $this->ensurePendingDispatch($profile, $run);

                    return;
                }
                if ($state === GtmResearchRunState::Running->value) {
                    if ($this->phases->recoveryDue($run)) {
                        $repaired += $this->requeueStaleRunning($profile, $run);
                    }

                    return;
                }

                if (! in_array($state, [
                    GtmResearchRunState::AwaitingProvider->value,
                    GtmResearchRunState::AwaitingBudget->value,
                ], true)) {
                    return;
                }

                // Provider recovery is intentionally allowed to enqueue
                // before budget admission is known. The worker performs
                // the authoritative reservation and moves the run to
                // awaiting_budget without making a provider call when it
                // cannot admit the first operation.
                $recovered = $this->research->recoverAwaitingRun(
                    $profile,
                    $run,
                    $state === GtmResearchRunState::AwaitingBudget->value,
                );
                if ($recovered) {
                    $repaired++;
                }
            });

        return $repaired;
    }

    private function ensurePendingDispatch(GtmProfile $profile, GtmProfileResearchRun $run): int
    {
        return DB::transaction(function () use ($profile, $run): int {
            $dispatch = GtmProfileResearchDispatch::query()
                ->where('gtm_profile_id', $profile->id)
                ->where('research_run_id', $run->id)
                ->lockForUpdate()
                ->first();
            if ($dispatch !== null) {
                $publishedAt = $dispatch->published_at;
                if ($publishedAt !== null && $publishedAt->lte(now()->subSeconds(max(
                    30,
                    (int) config('gtm.dispatch.lease_seconds', 300),
                )))) {
                    $dispatch->forceFill([
                        'status' => 'pending',
                        'available_at' => now(),
                        'published_at' => null,
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                        'last_error_code' => null,
                    ])->save();

                    return 1;
                }

                return 0;
            }

            GtmProfileResearchDispatch::query()->create([
                'gtm_profile_id' => $profile->id,
                'research_run_id' => $run->id,
                'status' => 'pending',
                'available_at' => now(),
                'published_at' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'publish_attempts' => 0,
                'last_error_code' => null,
            ]);

            return 1;
        }, 3);
    }

    private function requeueStaleRunning(GtmProfile $profile, GtmProfileResearchRun $run): int
    {
        return DB::transaction(function () use ($profile, $run): int {
            $lockedProfile = GtmProfile::query()->whereKey($profile->id)->lockForUpdate()->first();
            $lockedRun = GtmProfileResearchRun::query()
                ->whereKey($run->id)
                ->where('gtm_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();
            if ($lockedProfile === null || $lockedRun === null
                || (string) $lockedProfile->current_research_run_id !== (string) $lockedRun->id
                || $lockedRun->state !== GtmResearchRunState::Running
                || ! $this->phases->recoveryDue($lockedRun)) {
                return 0;
            }

            $lockedProfile->forceFill([
                'research_state' => GtmProfileResearchState::Queued,
                'awaiting_reason' => null,
            ])->save();
            $lockedRun->forceFill([
                'state' => GtmResearchRunState::Queued,
                'awaiting_reason' => null,
                'queued_at' => now(),
            ])->save();

            $dispatch = GtmProfileResearchDispatch::query()
                ->where('gtm_profile_id', $lockedProfile->id)
                ->where('research_run_id', $lockedRun->id)
                ->lockForUpdate()
                ->first();
            if ($dispatch === null) {
                GtmProfileResearchDispatch::query()->create([
                    'gtm_profile_id' => $lockedProfile->id,
                    'research_run_id' => $lockedRun->id,
                    'status' => 'pending',
                    'available_at' => now(),
                    'published_at' => null,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'publish_attempts' => 0,
                    'last_error_code' => null,
                ]);
            } else {
                $dispatch->forceFill([
                    'status' => 'pending',
                    'available_at' => now(),
                    'published_at' => null,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => null,
                ])->save();
            }

            return 1;
        }, 3);
    }
}
