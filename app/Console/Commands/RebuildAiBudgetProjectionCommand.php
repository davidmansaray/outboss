<?php

namespace App\Console\Commands;

use App\Domain\Ai\Services\RebuildAiBudgetProjection;
use App\Models\AiBudgetPeriod;
use Illuminate\Console\Command;

class RebuildAiBudgetProjectionCommand extends Command
{
    protected $signature = 'ai:rebuild-budget-projections {--workspace=}';

    protected $description = 'Rebuild AI budget period counters from the append-only ledger and open reservations';

    public function handle(RebuildAiBudgetProjection $projections): int
    {
        $query = AiBudgetPeriod::query()->orderBy('id');
        $workspaceId = $this->option('workspace');
        if ($workspaceId !== null && $workspaceId !== '') {
            $query->where('workspace_id', (int) $workspaceId);
        }

        $count = 0;
        $query->eachById(function (AiBudgetPeriod $period) use ($projections, &$count): void {
            $projections->rebuild($period);
            $count++;
        });

        $this->info("Rebuilt {$count} AI budget period projection(s).");

        return self::SUCCESS;
    }
}
