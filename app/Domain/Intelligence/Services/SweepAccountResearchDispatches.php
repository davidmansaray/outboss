<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\Enums\AccountResearchRunState;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Jobs\Intelligence\RunAccountResearchStage;
use App\Models\AccountResearchDispatch;
use App\Models\AccountResearchRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Reclaim expired dispatch leases and enqueue ID-only stage jobs. */
final class SweepAccountResearchDispatches
{
    public function handle(?int $limit = null, ?int $workspaceId = null): int
    {
        $limit = max(1, min(1000, $limit ?? 100));
        $query = AccountResearchDispatch::query()
            ->whereNull('published_at')
            ->where(function ($query): void {
                $query->where(function ($pending): void {
                    $pending->where('state', 'pending')->where('available_at', '<=', now());
                })->orWhere(function ($leased): void {
                    $leased->where('state', 'leased')->where('publish_lease_expires_at', '<=', now());
                });
            });
        if ($workspaceId !== null) {
            $query->where('workspace_id', $workspaceId);
        }
        $ids = $query->orderBy('id')->limit($limit)->pluck('id');
        $published = 0;
        foreach ($ids as $id) {
            $dispatch = $this->claim((string) $id);
            if ($dispatch === null) {
                continue;
            }
            $run = AccountResearchRun::query()->where('workspace_id', $dispatch->workspace_id)->whereKey($dispatch->research_run_id)->first();
            $stage = $run?->stages()->where('stage_key', (string) $dispatch->stage_key)->first();
            if ($run === null || $stage === null || AccountResearchRunState::tryFrom(ModelAttribute::enumValue($run->state) ?? '')?->terminal() === true) {
                $this->failLease($dispatch, 'dispatch_missing');

                continue;
            }
            try {
                RunAccountResearchStage::dispatch((int) $dispatch->workspace_id, (string) $run->id, (string) $stage->id);
                $published++;
            } catch (\Throwable) {
                $this->failLease($dispatch, 'dispatch_failed');
            }
        }

        return $published;
    }

    private function claim(string $id): ?AccountResearchDispatch
    {
        return DB::transaction(function () use ($id): ?AccountResearchDispatch {
            $dispatch = AccountResearchDispatch::query()->whereKey($id)->lockForUpdate()->first();
            if ($dispatch === null || $dispatch->published_at !== null) {
                return null;
            }
            if (ModelAttribute::enumValue($dispatch->state) === 'pending' && ModelAttribute::instant($dispatch->available_at)?->isFuture() === true) {
                return null;
            }
            if (ModelAttribute::enumValue($dispatch->state) === 'leased' && ModelAttribute::instant($dispatch->publish_lease_expires_at)?->isFuture() === true) {
                return null;
            }
            $dispatch->forceFill([
                'state' => 'leased',
                'publish_lease_owner' => (string) Str::uuid(),
                'publish_lease_expires_at' => now()->addSeconds(max(30, (int) config('intelligence.research.attempt_lease_seconds', 300))),
                'publish_attempts' => (int) $dispatch->publish_attempts + 1,
            ])->save();

            return $dispatch->fresh();
        }, 3);
    }

    private function failLease(AccountResearchDispatch $dispatch, string $code): void
    {
        DB::table('account_research_dispatches')
            ->where('id', $dispatch->id)
            ->where('publish_lease_owner', $dispatch->publish_lease_owner)
            ->update([
                'state' => 'pending',
                'available_at' => now()->addSeconds(30),
                'publish_lease_owner' => null,
                'publish_lease_expires_at' => null,
                'safe_error_code' => $code,
                'updated_at' => now(),
            ]);
    }
}
