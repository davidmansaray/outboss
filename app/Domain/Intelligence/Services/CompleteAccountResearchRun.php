<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\Enums\AccountResearchRunState;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\AccountResearchRun;
use App\Models\AccountResearchStage;
use App\Models\ResearchClaim;
use App\Models\ResearchSourceSnapshot;
use Illuminate\Support\Facades\DB;

/** Finalize a fully processed run without overwriting cancellation or failure. */
final class CompleteAccountResearchRun
{
    public function __construct(private readonly FinalizeAccountResearchBudgets $budgets) {}

    public function handle(AccountResearchRun|string $run): ?AccountResearchRun
    {
        $runId = $run instanceof AccountResearchRun ? (string) $run->id : $run;
        $completed = DB::transaction(function () use ($runId): ?AccountResearchRun {
            $locked = AccountResearchRun::query()->whereKey($runId)->lockForUpdate()->first();
            if ($locked === null || ModelAttribute::enumValue($locked->state) !== AccountResearchRunState::Running->value) {
                return $locked;
            }
            $failed = AccountResearchStage::query()
                ->where('workspace_id', $locked->workspace_id)
                ->where('research_run_id', $locked->id)
                ->whereIn('state', ['failed', 'paid_result_lost'])
                ->exists();
            $usable = ResearchSourceSnapshot::query()
                ->where('workspace_id', $locked->workspace_id)
                ->where('research_run_id', $locked->id)
                ->whereIn('state', ['available', 'partial'])
                ->exists()
                || ResearchClaim::query()
                    ->where('workspace_id', $locked->workspace_id)
                    ->where('research_run_id', $locked->id)
                    ->exists();
            $locked->forceFill([
                'state' => $failed ? ($usable ? AccountResearchRunState::Partial : AccountResearchRunState::Failed) : AccountResearchRunState::Completed,
                'current_stage' => null,
                'awaiting_reason' => null,
                'finished_at' => now(),
            ])->save();

            return $locked->fresh();
        }, 3);

        if ($completed !== null
            && AccountResearchRunState::tryFrom(ModelAttribute::enumValue($completed->state) ?? '')?->terminal() === true) {
            $this->budgets->handle($completed);
        }

        return $completed;
    }
}
