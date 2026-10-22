<?php

namespace App\Domain\Gtm\Services;

use App\Jobs\Gtm\ResearchWorkspaceGtmProfile;
use App\Models\GtmProfileResearchDispatch;
use App\Models\GtmProfileResearchRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Publish durable research dispatch rows, reclaiming expired leases safely. */
final class PublishGtmProfileResearchDispatches
{
    public function handle(?int $limit = null): int
    {
        $limit = max(1, min(1000, $limit ?? (int) config('gtm.dispatch.batch_size', 100)));
        $published = 0;
        $dispatchIds = GtmProfileResearchDispatch::query()
            ->whereNull('published_at')
            ->where(function ($query): void {
                $query->where(function ($pending): void {
                    $pending->where('status', 'pending')
                        ->where('available_at', '<=', now());
                })->orWhere(function ($leased): void {
                    $leased->where('status', 'leased')
                        ->where('lease_expires_at', '<=', now());
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($dispatchIds as $dispatchId) {
            $lease = $this->claim((int) $dispatchId);
            if ($lease === null) {
                continue;
            }

            try {
                $run = GtmProfileResearchRun::query()
                    ->whereKey($lease->research_run_id)
                    ->where('gtm_profile_id', $lease->gtm_profile_id)
                    ->first();
                if ($run === null) {
                    $this->failLease($lease, 'dispatch_missing');

                    continue;
                }

                ResearchWorkspaceGtmProfile::dispatch(
                    (string) $lease->gtm_profile_id,
                    (string) $run->id,
                    $run->initiated_by_user_id === null ? null : (int) $run->initiated_by_user_id,
                );
                // ShouldBeUnique may suppress this dispatch without throwing.
                // Keep the durable row leased/unacknowledged until the worker
                // actually starts; an expired lease is then safe to republish.
                $published++;
            } catch (Throwable) {
                $this->failLease($lease, 'dispatch_failed');
            }
        }

        return $published;
    }

    private function claim(int $dispatchId): ?GtmProfileResearchDispatch
    {
        return DB::transaction(function () use ($dispatchId): ?GtmProfileResearchDispatch {
            $dispatch = GtmProfileResearchDispatch::query()->whereKey($dispatchId)->lockForUpdate()->first();
            if ($dispatch === null || $dispatch->published_at !== null) {
                return null;
            }
            if ($dispatch->status === 'pending' && $dispatch->available_at->isFuture()) {
                return null;
            }
            if ($dispatch->status === 'leased'
                && $dispatch->lease_expires_at !== null
                && $dispatch->lease_expires_at->isFuture()) {
                return null;
            }

            $dispatch->forceFill([
                'status' => 'leased',
                'lease_owner' => (string) Str::uuid(),
                'lease_expires_at' => now()->addSeconds(max(30, (int) config('gtm.dispatch.lease_seconds', 300))),
                'publish_attempts' => (int) $dispatch->publish_attempts + 1,
            ])->save();

            return $dispatch->fresh();
        }, 3);
    }

    private function failLease(GtmProfileResearchDispatch $dispatch, string $safeCode): void
    {
        DB::table('gtm_profile_research_dispatches')
            ->where('id', $dispatch->id)
            ->where('lease_owner', $dispatch->lease_owner)
            ->update([
                'status' => 'pending',
                'available_at' => now()->addSeconds(max(5, (int) config('gtm.dispatch.retry_seconds', 30))),
                'lease_owner' => null,
                'lease_expires_at' => null,
                'last_error_code' => in_array($safeCode, ['dispatch_missing', 'dispatch_failed'], true)
                    ? $safeCode
                    : 'dispatch_failed',
                'updated_at' => now(),
            ]);
    }
}
