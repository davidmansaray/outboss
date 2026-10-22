<?php

namespace App\Console\Commands;

use App\Domain\Prospecting\Services\ReconcileProspectSources;
use App\Models\Workspace;
use Illuminate\Console\Command;

final class ReconcileProspectSourcesCommand extends Command
{
    protected $signature = 'prospecting:reconcile-sources
        {--workspace= : Reconcile only this numeric workspace ID}
        {--repair : Repair counter projections}
        {--json : Emit machine-readable output}';

    protected $description = 'Report or repair prospect source lineage and counter mismatches';

    public function handle(ReconcileProspectSources $reconcile): int
    {
        $value = $this->option('workspace');
        if ($value !== null && $value !== '' && preg_match('/^[1-9][0-9]*$/', (string) $value) !== 1) {
            $this->error('The --workspace option must be a positive numeric workspace ID.');

            return self::INVALID;
        }
        $workspace = null;
        if ($value !== null && $value !== '') {
            $workspace = Workspace::query()->find((int) $value);
            if ($workspace === null) {
                $this->error("Workspace {$value} was not found.");

                return self::FAILURE;
            }
        }
        $report = $reconcile->handle($workspace, (bool) $this->option('repair'));
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($report['workspaces'] as $row) {
                $this->line(sprintf('workspace %d: %d mismatch(es)', $row['workspace_id'], $row['mismatch_count']));
            }
            $this->info("Total mismatches: {$report['mismatch_count']}.");
        }

        return (int) $report['mismatch_count'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
