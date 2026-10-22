<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\Enums\AccountResearchRunState;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\AccountResearchAttempt;
use App\Models\AccountResearchDispatch;
use App\Models\AccountResearchRun;
use App\Models\AccountResearchStage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Stop a run without deleting any completed evidence or audit history. */
final class CancelAccountResearch
{
    public function __construct(private readonly FinalizeAccountResearchBudgets $budgets) {}

    public function handle(Workspace $workspace, AccountResearchRun|string $run, ?User $actor = null): AccountResearchRun
    {
        if ($actor !== null && ! $workspace->isOwner($actor)) {
            throw new AuthorizationException('Only the workspace owner can cancel Account research.');
        }
        $runId = $run instanceof AccountResearchRun ? (string) $run->id : $run;

        $cancelling = DB::transaction(function () use ($workspace, $runId): AccountResearchRun {
            $locked = AccountResearchRun::query()->where('workspace_id', $workspace->id)->whereKey($runId)->lockForUpdate()->firstOrFail();
            if (AccountResearchRunState::tryFrom(ModelAttribute::enumValue($locked->state) ?? '')?->terminal() === true) {
                return $locked;
            }
            $locked->forceFill([
                // Provider execution cannot be interrupted safely. Fence the
                // research worker first, then let AF-01 settle/reconcile any
                // in-flight operation before this run becomes cancelled.
                'state' => AccountResearchRunState::Cancelling,
                'awaiting_reason' => null,
                'safe_failure_code' => null,
                'cancelled_at' => now(),
                'finished_at' => null,
            ])->save();
            $stages = AccountResearchStage::query()
                ->where('workspace_id', $workspace->id)
                ->where('research_run_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $attempts = AccountResearchAttempt::query()
                ->where('workspace_id', $workspace->id)
                ->where('research_run_id', $locked->id)
                ->whereIn('state', ['planned', 'running', 'provider_boundary', 'waiting_budget', 'ambiguous'])
                ->orderBy('stage_id')
                ->orderBy('attempt_number')
                ->lockForUpdate()
                ->get();
            foreach ($attempts as $attempt) {
                $attempt->forceFill([
                    'state' => 'cancelled',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'safe_error_code' => null,
                    'finished_at' => now(),
                ])->save();
            }
            foreach ($stages as $stage) {
                if (! in_array(ModelAttribute::enumValue($stage->state), ['pending', 'blocked', 'running'], true)) {
                    continue;
                }
                $stage->forceFill([
                    'state' => 'cancelled',
                    'lease_owner' => null,
                    'fencing_token' => (int) $stage->fencing_token + 1,
                    'lease_expires_at' => null,
                ])->save();
            }
            AccountResearchDispatch::query()
                ->where('workspace_id', $workspace->id)
                ->where('research_run_id', $locked->id)
                ->whereIn('state', ['pending', 'leased'])
                ->update(['state' => 'retired', 'publish_lease_owner' => null, 'publish_lease_expires_at' => null, 'updated_at' => now()]);

            return $locked->fresh();
        }, 3);

        if (AccountResearchRunState::tryFrom(ModelAttribute::enumValue($cancelling->state) ?? '')?->terminal() === true) {
            return $cancelling;
        }

        if (! $this->budgets->handle($cancelling)) {
            return $cancelling->fresh();
        }

        return DB::transaction(function () use ($workspace, $cancelling): AccountResearchRun {
            $locked = AccountResearchRun::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($cancelling->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (ModelAttribute::enumValue($locked->state) !== AccountResearchRunState::Cancelling->value) {
                return $locked->fresh();
            }
            $locked->forceFill([
                'state' => AccountResearchRunState::Cancelled,
                'finished_at' => now(),
            ])->save();

            return $locked->fresh();
        }, 3);
    }

    public function cancel(Workspace $workspace, AccountResearchRun|string $run, ?User $actor = null): AccountResearchRun
    {
        return $this->handle($workspace, $run, $actor);
    }
}
