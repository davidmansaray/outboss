<?php

namespace Tests\Support;

use App\Domain\Workspaces\Services\ProvisionWorkspace;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class WorkspaceProvisioningRaceTask
{
    /**
     * Build an autoloadable closure for Laravel's process concurrency driver.
     *
     * @param  array<string, mixed>  $baseConfiguration
     * @return Closure(): int
     */
    public static function make(
        array $baseConfiguration,
        string $database,
        string $connection,
        string $barrier,
        int $userId,
        int $worker,
    ): Closure {
        return static function () use (
            $baseConfiguration,
            $database,
            $connection,
            $barrier,
            $userId,
            $worker,
        ): int {
            $testConfiguration = $baseConfiguration;
            $testConfiguration['database'] = $database;
            config()->set("database.connections.{$connection}", $testConfiguration);
            config()->set('database.default', $connection);
            DB::purge($connection);
            DB::setDefaultConnection($connection);

            file_put_contents("{$barrier}/ready-{$worker}", 'ready');
            $deadline = microtime(true) + 10;
            while (count(glob("{$barrier}/ready-*") ?: []) < 2) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('workspace_concurrency_barrier_timed_out');
                }
                usleep(1_000);
            }

            $owner = User::query()->findOrFail($userId);

            return (int) app(ProvisionWorkspace::class)->create(
                $owner,
                'Concurrent Workspace',
                'team',
                null,
                'simultaneous-workspace-operation',
            )->id;
        };
    }
}
