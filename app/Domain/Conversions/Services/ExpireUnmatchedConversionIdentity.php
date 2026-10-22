<?php

namespace App\Domain\Conversions\Services;

use App\Models\ConversionEventReceipt;
use App\Models\Workspace;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/** Remove reversible unmatched identity material after its bounded deadline. */
final class ExpireUnmatchedConversionIdentity
{
    public function handle(?Workspace $workspace = null, ?int $limit = null): int
    {
        $limit = max(1, min(1000, $limit ?? 100));
        $query = ConversionEventReceipt::query()
            ->whereNotNull('unmatched_identity_envelope')
            ->whereNotNull('identity_expires_at')
            ->where('identity_expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit);
        if ($workspace !== null) {
            $query->where('workspace_id', $workspace->id);
        }
        $expired = 0;
        foreach ($query->get() as $receipt) {
            DB::transaction(function () use ($receipt, &$expired): void {
                $locked = ConversionEventReceipt::query()->whereKey($receipt->id)->lockForUpdate()->first();
                $expiresAt = $locked?->getAttribute('identity_expires_at');
                if ($locked === null || $locked->getAttribute('unmatched_identity_envelope') === null || ! $expiresAt instanceof DateTimeInterface || $expiresAt > now()) {
                    return;
                }
                $locked->project([
                    'unmatched_identity_envelope' => null,
                    'identity_redacted_at' => now(),
                ]);
                $expired++;
            }, 3);
        }

        return $expired;
    }

    public function expire(?Workspace $workspace = null, ?int $limit = null): int
    {
        return $this->handle($workspace, $limit);
    }
}
