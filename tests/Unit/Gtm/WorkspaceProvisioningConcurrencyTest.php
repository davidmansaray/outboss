<?php

use App\Models\GtmProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceClientOperation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WorkspaceProvisioningRaceTask;

it('serializes simultaneous workspace retries around one stable operation on MySQL', function () {
    $defaultConnection = (string) config('database.default');
    $baseConfiguration = config("database.connections.{$defaultConnection}");

    if (! is_array($baseConfiguration) || ($baseConfiguration['driver'] ?? null) !== 'mysql') {
        $this->markTestSkipped('This process-concurrency test requires an isolated MySQL database.');
    }

    $database = 'outboss_workspace_concurrency_'.Str::lower(Str::random(12));
    $connection = 'workspace_concurrency_test';
    $barrier = sys_get_temp_dir().'/'.$database;
    $createdDatabase = false;

    try {
        DB::connection($defaultConnection)->statement(
            "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        );
        $createdDatabase = true;
        mkdir($barrier, 0700, true);

        $testConfiguration = $baseConfiguration;
        $testConfiguration['database'] = $database;
        config()->set("database.connections.{$connection}", $testConfiguration);
        config()->set('database.default', $connection);
        DB::setDefaultConnection($connection);

        Artisan::call('migrate:fresh', [
            '--database' => $connection,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = User::query()->create([
            'name' => 'Concurrent Owner',
            'email' => 'concurrency-'.Str::lower(Str::random(12)).'@outboss.test',
            'timezone' => 'UTC',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
        ]);
        $userId = (int) $user->id;

        $tasks = [];
        foreach ([1, 2] as $worker) {
            $tasks[] = WorkspaceProvisioningRaceTask::make(
                $baseConfiguration,
                $database,
                $connection,
                $barrier,
                $userId,
                $worker,
            );
        }

        $workspaceIds = Concurrency::driver('process')->run($tasks, timeout: 30);

        expect($workspaceIds[0])->toBe($workspaceIds[1])
            ->and(Workspace::query()->where('name', 'Concurrent Workspace')->count())->toBe(1)
            ->and(WorkspaceClientOperation::query()
                ->where('initiating_user_id', $userId)
                ->where('client_operation_id', 'simultaneous-workspace-operation')
                ->count())->toBe(1)
            ->and(GtmProfile::query()->where('workspace_id', $workspaceIds[0])->count())->toBe(1);

        User::query()->findOrFail($userId)->delete();
        $audit = WorkspaceClientOperation::query()
            ->where('client_operation_id', 'simultaneous-workspace-operation')
            ->sole();

        expect($audit->initiating_user_id)->toBeNull()
            ->and($audit->resulting_workspace_id)->toBe($workspaceIds[0]);
    } finally {
        config()->set('database.default', $defaultConnection);
        DB::setDefaultConnection($defaultConnection);
        DB::purge($connection);

        foreach (glob("{$barrier}/ready-*") ?: [] as $marker) {
            unlink($marker);
        }
        if (is_dir($barrier)) {
            rmdir($barrier);
        }

        if ($createdDatabase) {
            DB::connection($defaultConnection)->statement("DROP DATABASE `{$database}`");
        }
    }
});
