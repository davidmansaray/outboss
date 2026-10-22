<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\Services\S2OperationalStatus;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Print the bounded S2 operational report without exposing row-level data.
 */
final class S2OperationalStatusCommand extends Command
{
    protected $signature = 'odg-s2:status
        {--workspace= : Optional workspace ID to scope the report}
        {--json : Emit the complete machine-readable report}';

    protected $description = 'Report bounded S2 operational metrics and alerts';

    /** @var list<string> */
    protected $aliases = ['s2:status', 's2:operations'];

    public function handle(S2OperationalStatus $status): int
    {
        $workspaceId = $this->workspaceId();
        if ($workspaceId === false) {
            return self::INVALID;
        }

        $report = $status->report($workspaceId);
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->renderSummary($report);
        }

        return ((int) ($report['alert_count'] ?? 0)) === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function workspaceId(): int|false|null
    {
        $value = $this->option('workspace');
        if ($value === null || $value === '') {
            return null;
        }

        if (! preg_match('/^[1-9][0-9]*$/', $value)) {
            $this->error('The --workspace option must be a positive numeric workspace ID.');

            return false;
        }

        $workspaceId = (int) $value;
        if ($workspaceId <= 0 || ! Workspace::query()->whereKey($workspaceId)->exists()) {
            $this->error('The requested workspace was not found.');

            return false;
        }

        return $workspaceId;
    }

    /** @param array<string,mixed> $report */
    private function renderSummary(array $report): void
    {
        $metrics = (array) ($report['metrics'] ?? []);
        $this->line(sprintf(
            'S2 status: %s (alerts: %d, scope: %s)',
            strtoupper((string) ($report['status'] ?? 'unknown')),
            (int) ($report['alert_count'] ?? 0),
            $report['scope']['workspace_id'] === null ? 'all workspaces' : (string) $report['scope']['workspace_id'],
        ));
        $this->table(
            ['Metric', 'Value'],
            [
                ['research_stuck', (int) data_get($metrics, 'research.stuck_count', 0)],
                ['provider_operations_stuck', (int) data_get($metrics, 'provider_operations.stuck_count', 0)],
                ['oldest_held_budget_age_seconds', data_get($metrics, 'budget.oldest_hold_age_seconds') ?? '—'],
                ['retention_backlog', (int) data_get($metrics, 'retention.backlog_count', 0)],
            ],
        );

        foreach ((array) ($report['alerts'] ?? []) as $alert) {
            $this->warn(sprintf(
                '%s [%s] value=%s threshold=%s',
                (string) ($alert['code'] ?? 'unknown'),
                (string) ($alert['severity'] ?? 'unknown'),
                (string) ($alert['value'] ?? 0),
                (string) ($alert['threshold'] ?? 0),
            ));
        }
    }
}
