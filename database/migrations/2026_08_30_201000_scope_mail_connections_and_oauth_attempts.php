<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasWorkspaceDuplicates = DB::table('provider_connections')
            ->select(['workspace_id', 'provider', 'provider_user_id'])
            ->groupBy('workspace_id', 'provider', 'provider_user_id')
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($hasWorkspaceDuplicates) {
            throw new RuntimeException(
                'Duplicate provider identities exist in a workspace; merge them before running this migration.',
            );
        }

        if (! Schema::hasColumn('oauth_attempts', 'workspace_id')) {
            Schema::table('oauth_attempts', function (Blueprint $table): void {
                $table->foreignId('workspace_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained()
                    ->cascadeOnDelete();
            });
        }

        DB::table('oauth_attempts')
            ->orderBy('id')
            ->eachById(function ($attempt): void {
                $workspaceId = DB::table('workspace_memberships')
                    ->join('workspaces', 'workspaces.id', '=', 'workspace_memberships.workspace_id')
                    ->where('workspace_memberships.user_id', $attempt->user_id)
                    ->orderByRaw("case when workspaces.type = 'personal' then 0 else 1 end")
                    ->orderBy('workspaces.id')
                    ->value('workspaces.id');

                if ($workspaceId !== null) {
                    DB::table('oauth_attempts')
                        ->where('id', $attempt->id)
                        ->update(['workspace_id' => $workspaceId]);
                }
            });

        // The legacy unique index is also the only index supporting the
        // provider_connections.user_id foreign key in MySQL. Add an explicit
        // replacement before removing it so InnoDB can preserve the FK.
        if (! Schema::hasIndex('provider_connections', 'provider_connections_user_id_index')) {
            Schema::table('provider_connections', function (Blueprint $table): void {
                $table->index('user_id', 'provider_connections_user_id_index');
            });
        }

        if (! Schema::hasIndex('provider_connections', 'provider_connections_workspace_provider_identity_unique')) {
            Schema::table('provider_connections', function (Blueprint $table): void {
                $table->unique(
                    ['workspace_id', 'provider', 'provider_user_id'],
                    'provider_connections_workspace_provider_identity_unique',
                );
            });
        }

        if (Schema::hasIndex('provider_connections', 'provider_connections_user_id_provider_provider_user_id_unique')) {
            Schema::table('provider_connections', function (Blueprint $table): void {
                $table->dropUnique('provider_connections_user_id_provider_provider_user_id_unique');
            });
        }
    }

    public function down(): void
    {
        $hasUserDuplicates = DB::table('provider_connections')
            ->select(['user_id', 'provider', 'provider_user_id'])
            ->groupBy('user_id', 'provider', 'provider_user_id')
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($hasUserDuplicates) {
            throw new RuntimeException(
                'Provider identities now exist in multiple workspaces for one user; merge them before rolling back.',
            );
        }

        if (! Schema::hasIndex('provider_connections', 'provider_connections_user_id_provider_provider_user_id_unique')) {
            Schema::table('provider_connections', function (Blueprint $table): void {
                $table->unique(['user_id', 'provider', 'provider_user_id']);
            });
        }

        if (Schema::hasIndex('provider_connections', 'provider_connections_workspace_provider_identity_unique')) {
            Schema::table('provider_connections', function (Blueprint $table): void {
                $table->dropUnique('provider_connections_workspace_provider_identity_unique');
            });
        }

        if (Schema::hasIndex('provider_connections', 'provider_connections_user_id_index')) {
            Schema::table('provider_connections', function (Blueprint $table): void {
                $table->dropIndex('provider_connections_user_id_index');
            });
        }

        if (Schema::hasColumn('oauth_attempts', 'workspace_id')) {
            Schema::table('oauth_attempts', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('workspace_id');
            });
        }
    }
};
