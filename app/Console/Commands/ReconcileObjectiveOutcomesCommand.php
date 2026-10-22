<?php

namespace App\Console\Commands;

use App\Domain\Objectives\Services\ReconcileObjectiveAttribution;
use App\Domain\Objectives\Services\ReconcileObjectiveConversations;
use App\Domain\Objectives\Services\ReconcileObjectiveExecution;
use App\Models\Workspace;
use Illuminate\Console\Command;

final class ReconcileObjectiveOutcomesCommand extends Command
{
    protected $signature = 'odg-s3:reconcile-outcomes
        {--workspace= : Restrict repair to one workspace ID}
        {--limit=100 : Maximum candidates per projection type (1-500)}
        {--json : Emit a machine-readable repair receipt}';

    protected $description = 'Repair bounded Objective exposure, conversation and attribution projections without sending mail';

    public function handle(
        ReconcileObjectiveExecution $execution,
        ReconcileObjectiveConversations $conversations,
        ReconcileObjectiveAttribution $attribution,
    ): int {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) {
            $this->error('The --limit option must be an integer from 1 to 500.');

            return self::INVALID;
        }
        $workspaceId = null;
        $workspaceOption = $this->option('workspace');
        if ($workspaceOption !== null && $workspaceOption !== '') {
            $workspaceId = filter_var($workspaceOption, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($workspaceId === false || ! Workspace::query()->whereKey($workspaceId)->exists()) {
                $this->error('The --workspace option must identify an existing workspace.');

                return self::INVALID;
            }
        }
        $counts = [
            'execution' => $execution->handle($workspaceId, $limit),
            'conversations' => $conversations->handle($workspaceId, $limit),
            'attribution' => $attribution->handle($workspaceId, $limit),
        ];
        $repaired = array_sum($counts);
        if ($this->option('json')) {
            $this->line(json_encode([
                'workspace_id' => $workspaceId,
                'limit' => $limit,
                'repaired' => $repaired,
                'projections' => $counts,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info("Repaired {$repaired} Objective execution projections. No mail was sent.");
        }

        return self::SUCCESS;
    }
}
