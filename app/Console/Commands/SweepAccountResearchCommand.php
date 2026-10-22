<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\Services\SweepAccountResearchDispatches;
use App\Models\Workspace;
use Illuminate\Console\Command;

final class SweepAccountResearchCommand extends Command
{
    protected $signature = 'account-research:sweep
        {--workspace= : Process only this numeric workspace ID}
        {--limit=100 : Maximum dispatches claimed in each pass}
        {--passes=1 : Maximum passes through newly-created dispatches}
        {--json : Emit machine-readable output}';

    protected $description = 'Publish ready Account research stage dispatches';

    public function handle(SweepAccountResearchDispatches $sweeper): int
    {
        $workspaceId = $this->positiveIntegerOption('workspace', optional: true);
        if ($workspaceId === false) {
            return self::INVALID;
        }
        if (is_int($workspaceId) && ! Workspace::query()->whereKey($workspaceId)->exists()) {
            $this->error("Workspace {$workspaceId} was not found.");

            return self::FAILURE;
        }

        $limit = $this->positiveIntegerOption('limit');
        $passes = $this->positiveIntegerOption('passes');
        if ($limit === false || $passes === false) {
            return self::INVALID;
        }

        $published = 0;
        $completedPasses = 0;
        for ($pass = 0; $pass < min($passes, 100); $pass++) {
            $count = $sweeper->handle(min($limit, 1000), $workspaceId ?: null);
            $published += $count;
            $completedPasses++;
            if ($count === 0) {
                break;
            }
        }

        $result = [
            'workspace_id' => $workspaceId ?: null,
            'passes' => $completedPasses,
            'published' => $published,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            $this->info("Published {$published} Account research dispatch(es) in {$completedPasses} pass(es).");
        }

        return self::SUCCESS;
    }

    private function positiveIntegerOption(string $name, bool $optional = false): int|false|null
    {
        $value = $this->option($name);
        if ($optional && ($value === null || $value === '')) {
            return null;
        }
        if ((! is_string($value) && ! is_int($value)) || preg_match('/^[1-9][0-9]*$/', (string) $value) !== 1) {
            $this->error("The --{$name} option must be a positive integer.");

            return false;
        }

        return (int) $value;
    }
}
