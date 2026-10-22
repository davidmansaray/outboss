<?php

namespace App\Console\Commands;

use App\Domain\Workspaces\Services\ProvisionWorkspace;
use App\Models\Workspace;
use Illuminate\Console\Command;

class BackfillGtmProfilesCommand extends Command
{
    protected $signature = 'gtm:backfill-profiles
        {--workspace= : Provision only this numeric workspace ID}
        {--chunk=200 : Number of workspaces to process per database chunk}';

    protected $description = 'Provision the baseline GTM profile and version for existing workspaces';

    public function handle(ProvisionWorkspace $provisioner): int
    {
        $query = Workspace::query()->orderBy('id');
        $workspaceId = $this->option('workspace');
        if ($workspaceId !== null && $workspaceId !== '') {
            if (preg_match('/^[1-9][0-9]*$/', $workspaceId) !== 1) {
                $this->error('The --workspace option must be a positive numeric workspace ID.');

                return self::FAILURE;
            }

            $query->whereKey((int) $workspaceId);
        }

        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);
        if (! is_int($chunk)) {
            $this->error('The --chunk option must be an integer from 1 to 1000.');

            return self::FAILURE;
        }

        $scanned = 0;
        $created = 0;
        $query->eachById(function (Workspace $workspace) use ($provisioner, &$scanned, &$created): void {
            $hadProfile = $workspace->gtmProfile()->exists();
            $provisioner->ensure($workspace);
            $scanned++;
            if (! $hadProfile) {
                $created++;
            }
        }, $chunk);

        if ($workspaceId !== null && $workspaceId !== '' && $scanned === 0) {
            $this->error("Workspace {$workspaceId} was not found.");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Scanned %d workspace(s): created %d GTM profile(s), %d already provisioned.',
            $scanned,
            $created,
            $scanned - $created,
        ));

        return self::SUCCESS;
    }
}
