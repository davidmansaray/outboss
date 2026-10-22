<?php

namespace App\Console\Commands;

use App\Domain\Objectives\Services\EnsureDefaultOutreachPolicy;
use App\Models\OutreachPolicy;
use App\Models\Workspace;
use Illuminate\Console\Command;

final class BackfillDefaultOutreachPoliciesCommand extends Command
{
    protected $signature = 'objectives:backfill-default-policies
        {--workspace= : Backfill one numeric workspace ID}
        {--chunk-size=100 : Workspaces per resumable chunk}
        {--after-id=0 : Resume after this workspace ID}
        {--dry-run : Report missing policies without writing}';

    protected $description = 'Provision the conservative Objective outreach policy for existing workspaces';

    public function handle(EnsureDefaultOutreachPolicy $policies): int
    {
        $workspaceId = $this->positiveIntegerOption('workspace', nullable: true);
        $chunkSize = $this->positiveIntegerOption('chunk-size');
        $afterId = $this->nonNegativeIntegerOption('after-id');
        if ($workspaceId === false || $chunkSize === false || $afterId === false || (is_int($chunkSize) && $chunkSize > 1000)) {
            $this->error('Options must be numeric; --chunk-size must be between 1 and 1000.');

            return self::INVALID;
        }

        $query = Workspace::query()->orderBy('id')->where('id', '>', $afterId);
        if (is_int($workspaceId)) {
            $query->whereKey($workspaceId);
        }
        $scanned = 0;
        $created = 0;
        $alreadyPresent = 0;
        $query->chunkById((int) $chunkSize, function ($workspaces) use ($policies, &$scanned, &$created, &$alreadyPresent): void {
            foreach ($workspaces as $workspace) {
                $scanned++;
                $exists = OutreachPolicy::query()->where('workspace_id', $workspace->id)->exists();
                if ($exists) {
                    $alreadyPresent++;

                    continue;
                }
                if (! $this->option('dry-run')) {
                    $policies->handle($workspace);
                }
                $created++;
                $this->line("workspace {$workspace->id}: ".($this->option('dry-run') ? 'would create' : 'created'));
            }
        });
        $this->info("Scanned {$scanned} workspace(s): {$created} missing, {$alreadyPresent} already provisioned.");

        return self::SUCCESS;
    }

    private function positiveIntegerOption(string $name, bool $nullable = false): int|false|null
    {
        $value = $this->option($name);
        if ($nullable && ($value === null || $value === '')) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($parsed) ? $parsed : false;
    }

    private function nonNegativeIntegerOption(string $name): int|false
    {
        $parsed = filter_var($this->option($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return is_int($parsed) ? $parsed : false;
    }
}
