<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Ai\Services\AiBudgetGateway;
use App\Models\AiBudgetEnvelope;
use App\Models\AiBudgetRun;
use App\Models\ObjectiveRun;
use App\Models\ObjectiveRunArmDraft;
use RuntimeException;

/** Close an Objective run's shared AI accounting only at authoritative boundaries. */
final class FinalizeObjectiveRunBudget
{
    public function __construct(private readonly AiBudgetGateway $budget) {}

    /** Close terminal drafting work while leaving provider ambiguity open. */
    public function closeTerminal(ObjectiveRun $run): bool
    {
        $settled = true;
        $drafts = $run->armDrafts()->with('budgetRun')->orderBy('id')->get();
        foreach ($drafts as $draft) {
            if (! $draft->state->terminal() || ! $this->closeRun($draft)) {
                $settled = false;
            }
        }

        return $settled && $this->closeEnvelope($run);
    }

    /** Cancel work that definitely cannot reach a provider, then close if safe. */
    public function cancelUnsent(ObjectiveRun $run, string $code, string $detail): bool
    {
        $settled = true;
        $drafts = $run->armDrafts()->with('budgetRun')->orderBy('id')->get();
        foreach ($drafts as $draft) {
            $budgetRun = $draft->budgetRun;
            if (! $budgetRun instanceof AiBudgetRun) {
                $settled = false;

                continue;
            }
            if ($budgetRun->closed_at !== null || in_array($budgetRun->status, ['closed', 'cancelled'], true)) {
                continue;
            }
            try {
                $this->budget->cancelRun($budgetRun, $code, $detail);
            } catch (RuntimeException) {
                // Executing or uncertain provider work remains open for the
                // existing reconciliation boundary. It is never reissued.
                $settled = false;
            }
        }

        return $settled && $this->closeEnvelope($run);
    }

    private function closeRun(ObjectiveRunArmDraft $draft): bool
    {
        $budgetRun = $draft->budgetRun;
        if (! $budgetRun instanceof AiBudgetRun) {
            return false;
        }
        if ($budgetRun->closed_at !== null || in_array($budgetRun->status, ['closed', 'cancelled'], true)) {
            return true;
        }

        try {
            $this->budget->closeRun($budgetRun);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    private function closeEnvelope(ObjectiveRun $run): bool
    {
        if ($run->ai_budget_envelope_id === null) {
            return true;
        }
        $envelope = AiBudgetEnvelope::query()
            ->where('workspace_id', $run->workspace_id)
            ->whereKey($run->ai_budget_envelope_id)
            ->first();
        if ($envelope === null) {
            return false;
        }
        if ($envelope->closed_at !== null || in_array($envelope->state, ['closed', 'cancelled'], true)) {
            return true;
        }

        try {
            $this->budget->closeEnvelope($envelope);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }
}
