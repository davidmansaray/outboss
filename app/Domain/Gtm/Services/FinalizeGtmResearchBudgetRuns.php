<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Gtm\Enums\GtmResearchRunState;
use App\Models\AiBudgetRun;
use App\Models\AiProviderOperation;
use App\Models\GtmProfileResearchRun;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Finish AF-01 ledgers whose GTM orchestration is already terminal.
 *
 * Supersession can race an executing provider request, and a ready merge can
 * race settlement/closure. Those races are retried durably by the GTM sweep:
 * stale executing work first moves to AF-01 reconciliation, then a later pass
 * closes or cancels the orchestration-owned run after every hold is terminal.
 */
final class FinalizeGtmResearchBudgetRuns
{
    public function __construct(private readonly AiBudgetGateway $budget) {}

    public function handle(?int $limit = null, ?int $workspaceId = null): int
    {
        $limit = max(1, min(1000, $limit ?? (int) config('gtm.dispatch.batch_size', 100)));
        $finalized = 0;

        GtmProfileResearchRun::query()
            ->whereNotNull('budget_run_id')
            ->whereHas('budgetRun', fn ($query) => $query
                ->whereNull('closed_at')
                ->whereIn('status', ['open', 'breached']))
            ->where(function ($query): void {
                $query->whereIn('state', [
                    GtmResearchRunState::Ready->value,
                    GtmResearchRunState::Cancelled->value,
                ])->orWhere(function ($failed): void {
                    $failed->where('state', GtmResearchRunState::Failed->value)
                        ->whereIn('safe_failure_code', ['paid_result_lost', 'retry_exhausted']);
                });
            })
            ->when($workspaceId !== null, fn ($query) => $query->whereHas(
                'profile',
                fn ($profile) => $profile->where('workspace_id', $workspaceId),
            ))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get()
            ->each(function (GtmProfileResearchRun $run) use (&$finalized): void {
                $finalized += $this->finalize($run);
            });

        return $finalized;
    }

    private function finalize(GtmProfileResearchRun $run): int
    {
        $budgetRun = AiBudgetRun::query()->whereKey($run->budget_run_id)->first();
        if ($budgetRun === null
            || $budgetRun->closed_at !== null
            || in_array((string) $budgetRun->status, ['closed', 'cancelled'], true)) {
            return 0;
        }

        $changed = 0;
        $operations = AiProviderOperation::query()
            ->where('budget_run_id', $budgetRun->id)
            ->orderBy('created_at')
            ->get();
        foreach ($operations as $operation) {
            if (in_array($operation->status, [AiOperationStatus::Planned, AiOperationStatus::Reserved], true)) {
                $this->budget->releaseDefinitelyNotSent(
                    $operation,
                    'worker_interrupted_before_call',
                    'The terminal research run did not send this reserved provider request.',
                );
                $changed++;

                continue;
            }
            if ($operation->status === AiOperationStatus::Executing) {
                if ($this->operationIsFresh($operation)) {
                    return $changed;
                }
                $this->budget->markUncertain(
                    $operation,
                    'unknown_provider_failure',
                    'The terminal research run has stale provider execution that requires reconciliation.',
                );

                return $changed + 1;
            }
            if (in_array($operation->status, [AiOperationStatus::Uncertain, AiOperationStatus::Reconciling], true)) {
                return $changed;
            }
        }

        try {
            $fresh = $budgetRun->fresh();
            if ($run->state === GtmResearchRunState::Cancelled) {
                $this->budget->cancelRun($fresh, 'research_superseded', 'The terminal research run was cancelled.');
            } else {
                $this->budget->closeRun($fresh);
            }

            return $changed + 1;
        } catch (RuntimeException) {
            // Settlement may have changed concurrently. A later durable sweep
            // reloads fresh AF-01 state and retries without losing the ledger.
            return $changed;
        }
    }

    private function operationIsFresh(AiProviderOperation $operation): bool
    {
        if ($operation->lease_expires_at !== null && $operation->lease_expires_at->isFuture()) {
            return true;
        }

        $value = $operation->started_at ?? $operation->reserved_at ?? $operation->updated_at;
        if ($value === null) {
            return false;
        }

        try {
            return CarbonImmutable::parse($value)
                ->addSeconds(max(1, (int) config('gtm.research.attempt_lease_seconds', 300)))
                ->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }
}
