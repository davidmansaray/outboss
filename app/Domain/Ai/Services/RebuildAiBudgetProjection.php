<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Models\AiBudgetEnvelope;
use App\Models\AiBudgetPeriod;
use App\Models\AiBudgetRun;
use App\Models\AiProviderOperation;
use App\Models\AiUsageLedger;
use Illuminate\Support\Facades\DB;

class RebuildAiBudgetProjection
{
    public function rebuild(AiBudgetPeriod $period): AiBudgetPeriod
    {
        return DB::transaction(function () use ($period): AiBudgetPeriod {
            $locked = AiBudgetPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            $committed = (int) AiUsageLedger::query()
                ->where('budget_period_id', $locked->id)
                ->sum('cost_micros');
            $held = (int) AiProviderOperation::query()
                ->where('budget_period_id', $locked->id)
                ->whereIn('status', [
                    AiOperationStatus::Reserved,
                    AiOperationStatus::Executing,
                    AiOperationStatus::Uncertain,
                    AiOperationStatus::Reconciling,
                ])
                ->sum('reserved_cost_micros');
            $breached = AiProviderOperation::query()
                ->where('budget_period_id', $locked->id)
                ->whereColumn('actual_cost_micros', '>', 'reserved_cost_micros')
                ->exists();

            $locked->forceFill([
                'held_micros' => max(0, $held),
                'committed_micros' => max(0, $committed),
                'budget_breach' => $breached,
                'over_limit' => $breached || $committed + $held > (int) $locked->limit_snapshot_micros,
            ])->save();

            // Envelopes and task runs are projections of the same immutable
            // operation/ledger evidence. Rebuild them whenever their period
            // is repaired so a crash cannot leave a workflow hard maximum
            // overstated or understated.
            $envelopeIds = AiProviderOperation::query()
                ->where('budget_period_id', $locked->id)
                ->whereNotNull('budget_envelope_id')
                ->pluck('budget_envelope_id')
                ->unique()
                ->values();
            foreach ($envelopeIds as $envelopeId) {
                $envelope = AiBudgetEnvelope::query()->whereKey($envelopeId)->lockForUpdate()->first();
                if ($envelope === null) {
                    continue;
                }
                $envelopeCommitted = (int) AiUsageLedger::query()
                    ->where('budget_envelope_id', $envelope->id)
                    ->sum('cost_micros');
                $envelopeHeld = (int) AiProviderOperation::query()
                    ->where('budget_envelope_id', $envelope->id)
                    ->whereIn('status', [
                        AiOperationStatus::Reserved,
                        AiOperationStatus::Executing,
                        AiOperationStatus::Uncertain,
                        AiOperationStatus::Reconciling,
                    ])
                    ->sum('reserved_cost_micros');
                $envelope->forceFill([
                    'held_micros' => max(0, $envelopeHeld),
                    'committed_micros' => max(0, $envelopeCommitted),
                    'state' => $envelopeCommitted + $envelopeHeld > (int) $envelope->hard_limit_micros
                        ? 'breached'
                        : ($envelope->state === 'breached' ? 'breached' : $envelope->state),
                ])->save();

                foreach (AiBudgetRun::query()->where('budget_envelope_id', $envelope->id)->lockForUpdate()->get() as $run) {
                    $runCommitted = (int) AiUsageLedger::query()->where('budget_run_id', $run->id)->sum('cost_micros');
                    $runHeld = (int) AiProviderOperation::query()
                        ->where('budget_run_id', $run->id)
                        ->whereIn('status', [AiOperationStatus::Reserved, AiOperationStatus::Executing, AiOperationStatus::Uncertain, AiOperationStatus::Reconciling])
                        ->sum('reserved_cost_micros');
                    $run->forceFill(['held_micros' => max(0, $runHeld), 'committed_micros' => max(0, $runCommitted)])->save();
                }
            }

            return $locked->fresh();
        }, 3);
    }
}
