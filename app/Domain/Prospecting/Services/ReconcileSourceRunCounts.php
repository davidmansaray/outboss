<?php

namespace App\Domain\Prospecting\Services;

use App\Domain\Prospecting\Enums\ObservationDisposition;
use App\Models\SourceRun;
use Illuminate\Support\Facades\DB;

final class ReconcileSourceRunCounts
{
    /** Rebuild counter projections from immutable observation dispositions. */
    public function handle(SourceRun $run): SourceRun
    {
        return DB::transaction(function () use ($run): SourceRun {
            $locked = SourceRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $rows = $locked->observations()
                ->select('disposition', DB::raw('count(*) as aggregate'))
                ->groupBy('disposition')
                ->pluck('aggregate', 'disposition');
            $count = static fn (ObservationDisposition $disposition): int => (int) ($rows[$disposition->value] ?? 0);
            $received = (int) $rows->sum();
            $locked->forceFill([
                'received' => $received,
                'valid' => $received - $count(ObservationDisposition::Rejected),
                'accepted' => $count(ObservationDisposition::New) + $count(ObservationDisposition::Linked) + $count(ObservationDisposition::Duplicate),
                'created' => $count(ObservationDisposition::New),
                'linked' => $count(ObservationDisposition::Linked),
                'duplicate' => $count(ObservationDisposition::Duplicate),
                'conflicted' => $count(ObservationDisposition::Conflicted),
                'suppressed' => $count(ObservationDisposition::Suppressed),
                'rejected' => $count(ObservationDisposition::Rejected),
                'expired' => $count(ObservationDisposition::Expired),
            ])->save();

            return $locked->fresh();
        }, 3);
    }

    public function reconcile(SourceRun $run): SourceRun
    {
        return $this->handle($run);
    }
}
