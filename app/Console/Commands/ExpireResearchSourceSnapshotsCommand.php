<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\Services\ExpireResearchSourceSnapshots;
use App\Models\Workspace;
use Illuminate\Console\Command;

/** Run bounded S2 source retention catch-up without exposing row data. */
final class ExpireResearchSourceSnapshotsCommand extends Command
{
    protected $signature = 'odg-s2:expire-snapshots
        {--workspace= : Process only this numeric workspace ID}
        {--limit=100 : Maximum snapshots redacted in each pass}
        {--passes=1 : Maximum bounded passes}
        {--json : Emit machine-readable output}';

    protected $description = 'Redact expired S2 research source snapshots';

    /** @var list<string> */
    protected $aliases = ['s2:expire-snapshots', 'account-research:expire-snapshots'];

    public function handle(ExpireResearchSourceSnapshots $retention): int
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

        $redacted = 0;
        $completedPasses = 0;
        for ($pass = 0; $pass < min($passes, 100); $pass++) {
            $count = $retention->handle(
                $workspaceId === null ? null : Workspace::query()->find($workspaceId),
                min($limit, 1000),
            );
            $redacted += $count;
            $completedPasses++;
            if ($count === 0) {
                break;
            }
        }

        $result = [
            'workspace_id' => $workspaceId,
            'passes' => $completedPasses,
            'redacted' => $redacted,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            $this->info("Redacted {$redacted} expired S2 source snapshot(s) in {$completedPasses} pass(es).");
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
