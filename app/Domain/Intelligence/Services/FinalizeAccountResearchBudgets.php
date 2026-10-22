<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Intelligence\Enums\AccountResearchRunState;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\AccountResearchRun;
use App\Models\AiBudgetEnvelope;
use App\Models\AiBudgetRun;
use App\Models\AiProviderOperation;
use Carbon\CarbonImmutable;
use RuntimeException;

/** Close S2 accounting only after every provider outcome is authoritative. */
final class FinalizeAccountResearchBudgets
{
    public function __construct(private readonly AiBudgetGateway $budget) {}

    public function handle(AccountResearchRun|string $run): bool
    {
        $runId = $run instanceof AccountResearchRun ? (string) $run->id : $run;
        $current = AccountResearchRun::query()->whereKey($runId)->first();
        if ($current === null) {
            return false;
        }
        $state = AccountResearchRunState::tryFrom(ModelAttribute::enumValue($current->state) ?? '');
        if ($state?->terminal() !== true && $state !== AccountResearchRunState::Cancelling) {
            return false;
        }
        $cancellationRequested = in_array($state, [AccountResearchRunState::Cancelling, AccountResearchRunState::Cancelled], true);

        foreach ([$current->research_budget_run_id, $current->qualification_budget_run_id] as $budgetRunId) {
            if (! is_string($budgetRunId) || $budgetRunId === '') {
                continue;
            }
            $budgetRun = AiBudgetRun::query()
                ->where('workspace_id', $current->workspace_id)
                ->whereKey($budgetRunId)
                ->first();
            if ($budgetRun === null || ! $this->finalizeRun($budgetRun, $cancellationRequested)) {
                return false;
            }
        }

        if (is_string($current->budget_envelope_id) && $current->budget_envelope_id !== '') {
            $envelope = AiBudgetEnvelope::query()
                ->where('workspace_id', $current->workspace_id)
                ->whereKey($current->budget_envelope_id)
                ->first();
            if ($envelope === null) {
                return false;
            }
            if ($envelope->closed_at === null && ! in_array((string) $envelope->state, ['closed', 'cancelled'], true)) {
                try {
                    $this->budget->closeEnvelope($envelope);
                } catch (RuntimeException) {
                    return false;
                }
            }
        }

        return true;
    }

    private function finalizeRun(AiBudgetRun $run, bool $cancellationRequested): bool
    {
        if ($run->closed_at !== null || in_array((string) $run->status, ['closed', 'cancelled'], true)) {
            return true;
        }

        $operations = AiProviderOperation::query()
            ->where('workspace_id', $run->workspace_id)
            ->where('budget_run_id', $run->id)
            ->orderBy('id')
            ->get();
        $hasDefinitelyUnsentWork = false;
        foreach ($operations as $operation) {
            if (in_array($operation->status, [AiOperationStatus::Planned, AiOperationStatus::Reserved], true)) {
                // cancelRun() rechecks and releases every unclaimed hold in
                // one counter-ordered transaction. Do not release this row
                // independently: it could be claimed between this read and
                // a later operation lock.
                $hasDefinitelyUnsentWork = true;

                continue;
            }
            if ($operation->status === AiOperationStatus::Executing) {
                if ($this->operationIsFresh($operation)) {
                    return false;
                }
                $leaseOwner = $operation->lease_owner;
                if (! is_string($leaseOwner) || $leaseOwner === '') {
                    // Without the exact execution owner there is no safe
                    // conditional transition. Leave this operation for the
                    // reconciler rather than fencing an unknown worker.
                    return false;
                }
                $this->budget->markUncertain(
                    $operation,
                    'execution_lease_expired',
                    'The terminal Account research run has stale provider execution that requires reconciliation.',
                    leaseOwner: $leaseOwner,
                    fencingToken: (int) $operation->fencing_token,
                );

                return false;
            }
            if (in_array($operation->status, [AiOperationStatus::Uncertain, AiOperationStatus::Reconciling], true)) {
                return false;
            }
        }

        try {
            $fresh = $run->fresh();
            if ($fresh->closed_at !== null || in_array((string) $fresh->status, ['closed', 'cancelled'], true)) {
                return true;
            }
            if ($cancellationRequested || $hasDefinitelyUnsentWork) {
                $this->budget->cancelRun(
                    $fresh,
                    $cancellationRequested ? 'account_research_cancelled' : 'research_terminal_before_call',
                    $cancellationRequested
                        ? 'The Account research run was cancelled before completion.'
                        : 'The terminal Account research run did not send its reserved provider request.',
                );
            } else {
                $this->budget->closeRun($fresh);
            }
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    private function operationIsFresh(AiProviderOperation $operation): bool
    {
        if ($operation->lease_expires_at !== null) {
            return $operation->lease_expires_at->isFuture();
        }
        $reference = $operation->started_at ?? $operation->reserved_at ?? $operation->updated_at;
        if ($reference === null) {
            return false;
        }

        try {
            return CarbonImmutable::parse($reference)
                ->addSeconds(max(1, (int) config('intelligence.research.attempt_lease_seconds', 300)))
                ->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }
}
