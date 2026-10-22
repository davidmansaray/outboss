<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Objectives\Enums\ObjectiveRunDispatchState;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseName;
use App\Domain\Objectives\Enums\ObjectiveRunPhaseState;
use App\Domain\Objectives\Enums\ObjectiveRunState;
use App\Models\AiBudgetEnvelope;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunDispatch;
use App\Models\ObjectiveRunPhase;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Recover expired work without ever issuing a second provider call. */
final class RecoverObjectiveRuns
{
    public function __construct(private readonly FinalizeObjectiveRunBudget $finalizeBudget) {}

    public function handle(?int $workspaceId = null, ?int $limit = null): int
    {
        $repaired = 0;
        ObjectiveRun::query()
            ->where('origin', 'ai')
            ->whereIn('state', [ObjectiveRunState::Running->value, ObjectiveRunState::Waiting->value, ObjectiveRunState::Planned->value])
            // Human approval is intentionally open-ended and is not worker
            // recovery work. Exclude it before LIMIT so a large approval
            // backlog cannot starve later eligible runs.
            ->where('current_phase', '!=', ObjectiveRunPhaseName::AwaitApproval->value)
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->orderBy('id')->limit(max(1, min(1000, $limit ?? 100)))
            ->each(function (ObjectiveRun $run) use (&$repaired): void {
                DB::transaction(function () use ($run, &$repaired): void {
                    $locked = ObjectiveRun::query()->where('workspace_id', $run->workspace_id)
                        ->whereKey($run->id)->lockForUpdate()->first();
                    if ($locked === null || ! in_array($locked->state, [ObjectiveRunState::Running, ObjectiveRunState::Waiting, ObjectiveRunState::Planned], true)) {
                        return;
                    }
                    if ($locked->current_phase === ObjectiveRunPhaseName::AwaitApproval) {
                        return;
                    }
                    $phase = $locked->phases()->where('phase', $locked->current_phase->value)
                        ->orderByDesc('attempt_number')->lockForUpdate()->first();
                    if ($phase === null) {
                        return;
                    }
                    if ($phase->state === ObjectiveRunPhaseState::Running && $phase->lease_expires_at?->isFuture()) {
                        return;
                    }
                    if ($phase->state === ObjectiveRunPhaseState::Waiting) {
                        if ($phase->phase !== ObjectiveRunPhaseName::DraftMessages
                            || ! $this->draftOperationsTerminal($locked)) {
                            return;
                        }
                    }
                    if (! in_array($phase->state, [ObjectiveRunPhaseState::Queued, ObjectiveRunPhaseState::Running, ObjectiveRunPhaseState::Waiting], true)) {
                        return;
                    }
                    $dispatch = $locked->dispatches()->where('phase', $locked->current_phase->value)
                        ->where('input_hash', $phase->input_hash)
                        ->lockForUpdate()
                        ->first();
                    if ($dispatch?->state === ObjectiveRunDispatchState::Pending) {
                        return;
                    }
                    if ($phase->state === ObjectiveRunPhaseState::Queued) {
                        $retryAfter = now()->subSeconds(max(1, (int) config('objectives.dispatch_retry_seconds', 30)));
                        if ($dispatch?->state === ObjectiveRunDispatchState::Published
                            && $dispatch->updated_at->isAfter($retryAfter)) {
                            return;
                        }
                        $this->resetDispatch($locked, $phase, $dispatch);
                        $locked->forceFill([
                            'state' => ObjectiveRunState::Running,
                            'lease_owner' => null,
                            'lease_expires_at' => null,
                            'safe_error_code' => null,
                            'safe_error_detail' => null,
                        ])->save();
                        $repaired++;

                        return;
                    }
                    $attempt = (int) $locked->phases()->where('phase', $locked->current_phase->value)->max('attempt_number') + 1;
                    $recoveryFence = (int) $locked->fencing_token + 1;
                    $replacement = ObjectiveRunPhase::query()->create([
                        'id' => (string) Str::uuid(),
                        'workspace_id' => $locked->workspace_id,
                        'objective_run_id' => $locked->id,
                        'phase' => $locked->current_phase,
                        'attempt_number' => $attempt,
                        'state' => ObjectiveRunPhaseState::Queued,
                        'input_hash' => $phase->input_hash,
                        'fencing_token' => 0,
                        'queued_at' => now(),
                    ]);
                    ObjectiveRunPhase::query()->whereKey($phase->id)->update([
                        'state' => ObjectiveRunPhaseState::Failed->value,
                        'safe_error_code' => 'lease_recovered',
                        'safe_error_detail' => 'The expired phase lease was replaced by a fenced attempt.',
                        'finished_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $this->resetDispatch($locked, $replacement, $dispatch);
                    $locked->forceFill([
                        'state' => ObjectiveRunState::Running,
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                        'fencing_token' => $recoveryFence,
                        'heartbeat_at' => now(),
                        'safe_error_code' => null,
                        'safe_error_detail' => null,
                    ])->save();
                    $repaired++;
                }, 3);
            });

        ObjectiveRun::query()
            ->select('objective_runs.*')
            ->join('ai_budget_envelopes as recovery_envelopes', function (JoinClause $join): void {
                $join->on('recovery_envelopes.id', '=', 'objective_runs.ai_budget_envelope_id')
                    ->on('recovery_envelopes.workspace_id', '=', 'objective_runs.workspace_id');
            })
            ->whereIn('objective_runs.state', [
                ObjectiveRunState::Completed->value,
                ObjectiveRunState::Partial->value,
                ObjectiveRunState::Failed->value,
                ObjectiveRunState::Cancelled->value,
                ObjectiveRunState::Superseded->value,
            ])
            ->whereNotNull('objective_runs.ai_budget_envelope_id')
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->whereNull('recovery_envelopes.closed_at')
                        ->whereNotIn('recovery_envelopes.state', ['closed', 'cancelled']);
                })->orWhereColumn(
                    'objective_runs.reserved_cost_micros',
                    '!=',
                    'recovery_envelopes.held_micros',
                )->orWhereColumn(
                    'objective_runs.actual_cost_micros',
                    '!=',
                    'recovery_envelopes.committed_micros',
                );
            })
            ->when($workspaceId !== null, fn ($query) => $query->where('objective_runs.workspace_id', $workspaceId))
            ->orderBy('objective_runs.id')->limit(max(1, min(1000, $limit ?? 100)))
            ->each(function (ObjectiveRun $run): void {
                $this->recoverTerminalBudget($run);
            });

        return $repaired;
    }

    private function recoverTerminalBudget(ObjectiveRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $locked = ObjectiveRun::query()
                ->where('workspace_id', $run->workspace_id)
                ->whereKey($run->id)
                ->whereIn('state', [
                    ObjectiveRunState::Completed->value,
                    ObjectiveRunState::Partial->value,
                    ObjectiveRunState::Failed->value,
                    ObjectiveRunState::Cancelled->value,
                    ObjectiveRunState::Superseded->value,
                ])
                ->whereNotNull('ai_budget_envelope_id')
                ->lockForUpdate()
                ->first();
            if ($locked === null || ! $this->finalizeBudget->cancelUnsent(
                $locked,
                'objective_run_terminal',
                'The terminal Objective run cannot issue additional provider work.',
            )) {
                return;
            }

            $envelope = AiBudgetEnvelope::query()
                ->where('workspace_id', $locked->workspace_id)
                ->whereKey($locked->ai_budget_envelope_id)
                ->lockForUpdate()
                ->first();
            if ($envelope === null
                || ($envelope->closed_at === null && ! in_array($envelope->state, ['closed', 'cancelled'], true))) {
                return;
            }

            // Copy the authoritative envelope projection rather than adding a
            // delta. A replay therefore cannot double-charge the Objective
            // run, including when a prior recovery closed the envelope but
            // crashed before these two counters were persisted.
            $locked->forceFill([
                'reserved_cost_micros' => (int) $envelope->held_micros,
                'actual_cost_micros' => (int) $envelope->committed_micros,
            ])->save();
        }, 3);
    }

    private function draftOperationsTerminal(ObjectiveRun $run): bool
    {
        foreach ($run->armDrafts()->with('providerOperation')->get() as $draft) {
            $operation = $draft->providerOperation;
            if ($operation?->status === AiOperationStatus::Reserved) {
                // Reserved is durable proof that no provider call has been
                // claimed yet. Requeue the drafting phase so it can claim
                // this exact operation/key rather than waiting forever.
                continue;
            }
            if ($operation !== null && ! in_array($operation->status, [
                AiOperationStatus::Succeeded,
                AiOperationStatus::Failed,
                AiOperationStatus::Reconciled,
                AiOperationStatus::Cancelled,
                AiOperationStatus::ManualReview,
            ], true)) {
                return false;
            }
        }

        return true;
    }

    private function resetDispatch(
        ObjectiveRun $run,
        ObjectiveRunPhase $phase,
        ?ObjectiveRunDispatch $dispatch,
    ): ObjectiveRunDispatch {
        if ($dispatch === null) {
            return ObjectiveRunDispatch::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $run->workspace_id,
                'objective_run_id' => $run->id,
                'phase' => $phase->phase,
                'input_hash' => $phase->input_hash,
                'state' => ObjectiveRunDispatchState::Pending,
                'attempts' => 0,
                'available_at' => now(),
                'safe_error_code' => 'recovered',
                'safe_error_detail' => 'The durable Objective dispatch was recovered.',
            ]);
        }

        $dispatch->forceFill([
            'state' => ObjectiveRunDispatchState::Pending,
            'available_at' => now(),
            'published_at' => null,
            'consumed_at' => null,
            'safe_error_code' => 'recovered',
            'safe_error_detail' => 'The durable Objective dispatch was recovered.',
        ])->save();

        return $dispatch;
    }
}
