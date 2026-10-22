<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Intelligence\Enums\AccountResearchAttemptState;
use App\Domain\Intelligence\Enums\AccountResearchRunState;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\AccountResearchAttempt;
use App\Models\AccountResearchDispatch;
use App\Models\AccountResearchRun;
use App\Models\AccountResearchStage;
use App\Models\AiProviderOperation;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Repair stale run state/dispatch rows without replaying provider calls. */
final class RecoverAccountResearchRuns
{
    public function __construct(
        private readonly CancelAccountResearch $cancel,
        private readonly FinalizeAccountResearchBudgets $budgets,
    ) {}

    public function handle(?int $workspaceId = null): int
    {
        $repaired = 0;
        AccountResearchRun::query()
            ->where('state', AccountResearchRunState::Cancelling->value)
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->orderBy('id')
            ->each(function (AccountResearchRun $run) use (&$repaired): void {
                $workspace = Workspace::query()->whereKey($run->workspace_id)->first();
                if ($workspace === null) {
                    return;
                }
                $finalized = $this->cancel->handle($workspace, $run);
                if (ModelAttribute::enumValue($finalized->state) === AccountResearchRunState::Cancelled->value) {
                    $repaired++;
                }
            });

        AccountResearchRun::query()
            ->whereIn('state', [
                AccountResearchRunState::Cancelled->value,
                AccountResearchRunState::Partial->value,
                AccountResearchRunState::Completed->value,
                AccountResearchRunState::Failed->value,
            ])
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->where(function ($query): void {
                $openRun = static fn ($budget): mixed => $budget
                    ->whereNull('closed_at')
                    ->whereIn('status', ['open', 'breached']);
                $query
                    ->whereHas('researchBudgetRun', $openRun)
                    ->orWhereHas('qualificationBudgetRun', $openRun)
                    ->orWhereHas('budgetEnvelope', static fn ($envelope) => $envelope
                        ->whereNull('closed_at')
                        ->whereIn('state', ['open', 'breached']));
            })
            ->orderBy('id')
            ->each(function (AccountResearchRun $run) use (&$repaired): void {
                if ($this->budgets->handle($run)) {
                    $repaired++;
                }
            });

        $query = AccountResearchRun::query()
            ->whereIn('state', [AccountResearchRunState::Running->value, AccountResearchRunState::AwaitingProvider->value, AccountResearchRunState::AwaitingBudget->value, AccountResearchRunState::Planned->value]);
        if ($workspaceId !== null) {
            $query->where('workspace_id', $workspaceId);
        }
        $query->orderBy('id')->each(function (AccountResearchRun $run) use (&$repaired): void {
            DB::transaction(function () use ($run, &$repaired): void {
                $lockedRun = AccountResearchRun::query()
                    ->whereKey($run->id)
                    ->where('workspace_id', $run->workspace_id)
                    ->lockForUpdate()
                    ->first();
                if ($lockedRun === null || ! in_array(ModelAttribute::enumValue($lockedRun->state), [
                    AccountResearchRunState::Running->value,
                    AccountResearchRunState::AwaitingProvider->value,
                    AccountResearchRunState::AwaitingBudget->value,
                    AccountResearchRunState::Planned->value,
                ], true)) {
                    return;
                }
                $stageKey = (string) ($lockedRun->current_stage ?: 'eligibility');
                $stage = AccountResearchStage::query()
                    ->where('workspace_id', $lockedRun->workspace_id)
                    ->where('research_run_id', $lockedRun->id)
                    ->where('stage_key', $stageKey)
                    ->lockForUpdate()
                    ->first();
                if ($stage === null || in_array(ModelAttribute::enumValue($stage->state), ['succeeded', 'skipped', 'failed', 'paid_result_lost', 'cancelled'], true)) {
                    return;
                }
                if (ModelAttribute::enumValue($lockedRun->state) === AccountResearchRunState::AwaitingProvider->value
                    && ! $this->providerOutcomeTerminal($lockedRun, $stage)) {
                    return;
                }
                $currentAttemptIsFresh = $this->currentAttemptHasFreshLease($lockedRun, $stage);
                $dispatch = AccountResearchDispatch::query()
                    ->where('workspace_id', $lockedRun->workspace_id)
                    ->where('research_run_id', $lockedRun->id)
                    ->where('dispatch_kind', 'stage')
                    ->where('stage_key', $stageKey)
                    ->lockForUpdate()
                    ->first();
                if ($dispatch !== null && $dispatch->published_at !== null && $currentAttemptIsFresh) {
                    // The published dispatch may already be executing in a
                    // worker that still owns the current stage attempt. Do
                    // not make it visible again while that worker is active.
                    return;
                }
                if ($dispatch !== null
                    && $dispatch->published_at === null
                    && in_array(ModelAttribute::enumValue($dispatch->state), ['pending', 'leased'], true)) {
                    return;
                }
                $attributes = [
                    'state' => 'pending',
                    'available_at' => now(),
                    'published_at' => null,
                    'publish_lease_owner' => null,
                    'publish_lease_expires_at' => null,
                    'safe_error_code' => 'recovered',
                ];
                if ($dispatch === null) {
                    AccountResearchDispatch::query()->create([
                        'id' => (string) Str::uuid(),
                        'workspace_id' => $lockedRun->workspace_id,
                        'research_run_id' => $lockedRun->id,
                        'dispatch_kind' => 'stage',
                        'stage_key' => ModelAttribute::enumValue($stage->stage_key) ?? 'eligibility',
                        'publish_attempts' => 0,
                        ...$attributes,
                    ]);
                } else {
                    $dispatch->forceFill($attributes)->save();
                }
                $repaired++;
            }, 3);
        });

        return $repaired;
    }

    private function currentAttemptHasFreshLease(AccountResearchRun $run, AccountResearchStage $stage): bool
    {
        $attempt = AccountResearchAttempt::query()
            ->where('workspace_id', $run->workspace_id)
            ->where('research_run_id', $run->id)
            ->where('stage_id', $stage->id)
            ->orderByDesc('attempt_number')
            ->lockForUpdate()
            ->first();

        if ($attempt === null || ! in_array(ModelAttribute::enumValue($attempt->state), [
            AccountResearchAttemptState::Planned->value,
            AccountResearchAttemptState::Running->value,
            AccountResearchAttemptState::ProviderBoundary->value,
        ], true)) {
            return false;
        }

        return is_string($attempt->lease_owner)
            && $attempt->lease_owner !== ''
            && ModelAttribute::instant($attempt->lease_expires_at)?->isFuture() === true;
    }

    private function providerOutcomeTerminal(AccountResearchRun $run, AccountResearchStage $stage): bool
    {
        $attempt = AccountResearchAttempt::query()
            ->where('workspace_id', $run->workspace_id)
            ->where('research_run_id', $run->id)
            ->where('stage_id', $stage->id)
            ->orderByDesc('attempt_number')
            ->lockForUpdate()
            ->first();
        if ($attempt === null) {
            // Provider-unavailable runs have no call to reconcile; re-dispatch
            // them so current credential readiness is rechecked.
            return true;
        }
        $operation = AiProviderOperation::query()
            ->where('workspace_id', $run->workspace_id)
            ->where(function ($query) use ($attempt): void {
                $query->where('idempotency_key', $attempt->deterministic_idempotency_key);
                if ($attempt->provider_operation_id !== null) {
                    $query->orWhere('id', $attempt->provider_operation_id);
                }
            })
            ->lockForUpdate()
            ->first();
        if ($operation === null) {
            // A missing operation for an explicitly ambiguous attempt cannot
            // prove that no provider call happened. Other pre-provider states
            // can safely retry their readiness/admission checks.
            return ModelAttribute::enumValue($attempt->state) !== AccountResearchAttemptState::Ambiguous->value;
        }

        return in_array($operation->status, [
            AiOperationStatus::Succeeded,
            AiOperationStatus::Failed,
            AiOperationStatus::Reconciled,
            AiOperationStatus::Cancelled,
            AiOperationStatus::ManualReview,
        ], true);
    }
}
