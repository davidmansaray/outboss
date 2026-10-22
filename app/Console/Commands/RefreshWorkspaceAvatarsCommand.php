<?php

namespace App\Console\Commands;

use App\Domain\Workspaces\Services\WorkspaceAvatarManager;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

final class RefreshWorkspaceAvatarsCommand extends Command
{
    protected $signature = 'workspaces:refresh-avatars
        {--workspace= : Refresh only this numeric workspace ID}
        {--missing : Refresh only workspaces without a cached avatar}
        {--stale-days= : Refresh only workspaces not attempted within this many days}
        {--routine : Apply the automatic daily/weekly retry policy}
        {--chunk=200 : Number of workspaces to process per database chunk}';

    protected $description = 'Queue safe favicon discovery for workspace avatars';

    public function handle(WorkspaceAvatarManager $avatars): int
    {
        $query = Workspace::query()
            ->with('gtmProfile.currentVersion')
            ->orderBy('id');

        $workspaceId = $this->option('workspace');
        if ($workspaceId !== null && $workspaceId !== '') {
            if (preg_match('/^[1-9][0-9]*$/', (string) $workspaceId) !== 1) {
                $this->error('The --workspace option must be a positive numeric workspace ID.');

                return self::FAILURE;
            }
            $query->whereKey((int) $workspaceId);
        }

        $routine = (bool) $this->option('routine');
        if ($routine && ((bool) $this->option('missing') || $this->option('stale-days') !== null)) {
            $this->error('The --routine option cannot be combined with --missing or --stale-days.');

            return self::FAILURE;
        }

        if ((bool) $this->option('missing')) {
            $query->whereNull('avatar_path');
        }

        $staleDays = $this->option('stale-days');
        if ($staleDays !== null && $staleDays !== '') {
            $validatedStaleDays = filter_var($staleDays, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 3650],
            ]);
            if (! is_int($validatedStaleDays)) {
                $this->error('The --stale-days option must be an integer from 1 to 3650.');

                return self::FAILURE;
            }
            $query->where(function ($builder) use ($validatedStaleDays): void {
                $builder
                    ->whereNull('avatar_refreshed_at')
                    ->orWhere('avatar_refreshed_at', '<=', now()->subDays($validatedStaleDays));
            });
        }

        if ($routine) {
            $this->applyRoutinePolicy($query);
        }

        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);
        if (! is_int($chunk)) {
            $this->error('The --chunk option must be an integer from 1 to 1000.');

            return self::FAILURE;
        }

        $scanned = 0;
        $queued = 0;
        $withoutWebsite = 0;
        $query->eachById(function (Workspace $workspace) use (
            $avatars,
            &$scanned,
            &$queued,
            &$withoutWebsite,
        ): void {
            $scanned++;
            $website = data_get($workspace, 'gtmProfile.currentVersion.payload.company.website_url.value');
            if (! is_string($website) || $website === '') {
                $withoutWebsite++;

                return;
            }

            $avatars->dispatchRefresh((int) $workspace->id, $website);
            $queued++;
        }, $chunk);

        if ($workspaceId !== null && $workspaceId !== '' && $scanned === 0) {
            $this->error("Workspace {$workspaceId} was not found or did not match the selected filters.");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Scanned %d workspace(s): queued %d avatar refresh(es), skipped %d without a website.',
            $scanned,
            $queued,
            $withoutWebsite,
        ));

        return self::SUCCESS;
    }

    /** @param Builder<Workspace> $query */
    private function applyRoutinePolicy(Builder $query): void
    {
        $missingRetryDays = max(1, min(30, (int) config('workspaces.avatars.missing_retry_days', 7)));
        $refreshAfterDays = max(1, min(365, (int) config('workspaces.avatars.refresh_after_days', 7)));
        $now = now();
        $dailyCutoff = $now->copy()->subDay();
        $missingCutoff = $now->copy()->subDays($missingRetryDays);
        $weeklyCutoff = $now->copy()->subDays($refreshAfterDays);

        $query->where(function ($builder) use (
            $dailyCutoff,
            $missingCutoff,
            $weeklyCutoff,
        ): void {
            $builder
                // A job missed while the queue was unavailable gets its first
                // attempt on the next scheduler pass.
                ->whereNull('avatar_refreshed_at')
                // Recent missing icons are tried once per day for a short
                // window, so a new favicon does not wait a full week.
                ->orWhere(function ($missing) use ($dailyCutoff, $missingCutoff): void {
                    $missing
                        ->whereNull('avatar_path')
                        ->whereNotNull('avatar_missing_since')
                        ->where('avatar_missing_since', '>=', $missingCutoff)
                        ->where('avatar_refreshed_at', '<=', $dailyCutoff);
                })
                // Cached icons and long-missing websites are kept fresh once
                // a week, which bounds routine outbound work.
                ->orWhere('avatar_refreshed_at', '<=', $weeklyCutoff);
        });
    }
}
