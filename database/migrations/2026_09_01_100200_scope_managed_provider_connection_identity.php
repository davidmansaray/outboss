<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WORKSPACE_IDENTITY_INDEX = 'provider_connections_workspace_provider_identity_unique';

    private const MANAGED_IDENTITY_INDEX = 'provider_connections_workspace_provider_instance_identity_unique';

    private const NORMALIZED_IDENTITY_INDEX = 'provider_connections_workspace_provider_identity_scope_unique';

    private const NORMALIZED_IDENTITY_COLUMN = 'provider_identity_scope';

    /**
     * NULL has different unique-index semantics across the supported MySQL and
     * SQLite versions. Keep managed_instance_key nullable for OAuth rows, but
     * enforce one OAuth identity as well by indexing a generated sentinel for
     * NULL while the managed index remains explicitly instance-scoped.
     */
    private const OAUTH_IDENTITY_SENTINEL = '__oauth__';

    public function up(): void
    {
        $this->backfillManagedInstanceKeys();

        if (! Schema::hasColumn('provider_connections', self::NORMALIZED_IDENTITY_COLUMN)) {
            Schema::table('provider_connections', function (Blueprint $table): void {
                $table->string(self::NORMALIZED_IDENTITY_COLUMN, 80)
                    ->storedAs("COALESCE(managed_instance_key, '".self::OAUTH_IDENTITY_SENTINEL."')")
                    ->after('managed_instance_key');
            });
        }

        // Add the replacement keys before removing the previous key. The
        // previous unique index is also the supporting index for the
        // provider_connections.workspace_id foreign key on MySQL.
        if (! Schema::hasIndex('provider_connections', self::MANAGED_IDENTITY_INDEX, 'unique')) {
            Schema::table('provider_connections', function (Blueprint $table): void {
                $table->unique(
                    ['workspace_id', 'provider', 'managed_instance_key', 'provider_user_id'],
                    self::MANAGED_IDENTITY_INDEX,
                );
            });
        }

        // The previous workspace-scoped key treats a remote account ID as
        // globally unique within a workspace. That is not true when two
        // intentionally isolated Stalwart instances both issue account "1".
        $this->dropIndexIfPresent(self::WORKSPACE_IDENTITY_INDEX, unique: true);

        if (! Schema::hasIndex('provider_connections', self::NORMALIZED_IDENTITY_INDEX, 'unique')) {
            Schema::table('provider_connections', function (Blueprint $table): void {
                $table->unique(
                    [
                        'workspace_id',
                        'provider',
                        'provider_user_id',
                        self::NORMALIZED_IDENTITY_COLUMN,
                    ],
                    self::NORMALIZED_IDENTITY_INDEX,
                );
            });
        }
    }

    public function down(): void
    {
        if (DB::table('provider_connections')
            ->select(['workspace_id', 'provider', 'provider_user_id'])
            ->groupBy('workspace_id', 'provider', 'provider_user_id')
            ->havingRaw('count(*) > 1')
            ->exists()) {
            throw new RuntimeException(
                'Provider identities now exist on multiple managed instances; merge them before rolling back.',
            );
        }

        // Restore the workspace-scoped index before dropping either
        // replacement index; MySQL may be using one of them as the supporting
        // index for the provider_connections.workspace_id foreign key.
        if (! Schema::hasIndex('provider_connections', self::WORKSPACE_IDENTITY_INDEX, 'unique')) {
            Schema::table('provider_connections', function (Blueprint $table): void {
                $table->unique(
                    ['workspace_id', 'provider', 'provider_user_id'],
                    self::WORKSPACE_IDENTITY_INDEX,
                );
            });
        }

        $this->dropIndexIfPresent(self::NORMALIZED_IDENTITY_INDEX, unique: true);
        $this->dropIndexIfPresent(self::MANAGED_IDENTITY_INDEX, unique: true);

        if (Schema::hasColumn('provider_connections', self::NORMALIZED_IDENTITY_COLUMN)) {
            Schema::table('provider_connections', function (Blueprint $table): void {
                $table->dropColumn(self::NORMALIZED_IDENTITY_COLUMN);
            });
        }

    }

    private function backfillManagedInstanceKeys(): void
    {
        if (! Schema::hasTable('managed_mailbox_accounts') || ! Schema::hasTable('managed_mail_domains')) {
            return;
        }

        DB::table('provider_connections')
            ->where('connection_kind', 'managed')
            ->where(function ($query): void {
                $query->whereNull('managed_instance_key')->orWhere('managed_instance_key', '');
            })
            ->orderBy('id')
            ->eachById(function (object $connection): void {
                $instanceKey = DB::table('managed_mailbox_accounts as accounts')
                    ->join('managed_mail_domains as domains', 'domains.id', '=', 'accounts.managed_mail_domain_id')
                    ->where('accounts.mailbox_id', function ($query) use ($connection): void {
                        $query->select('mailbox_id')
                            ->from('mailboxes')
                            ->where('provider_connection_id', $connection->id)
                            ->orderBy('id')
                            ->limit(1);
                    })
                    ->value('domains.managed_instance_key');

                if (! is_string($instanceKey) || trim($instanceKey) === '') {
                    throw new RuntimeException(
                        'A managed provider connection has no managed instance key; reconcile it before migrating.',
                    );
                }

                DB::table('provider_connections')
                    ->where('id', $connection->id)
                    ->update(['managed_instance_key' => trim($instanceKey)]);
            });
    }

    private function dropIndexIfPresent(string $indexName, bool $unique = false): void
    {
        if (! Schema::hasIndex('provider_connections', $indexName, $unique ? 'unique' : null)) {
            return;
        }

        Schema::table('provider_connections', function (Blueprint $table) use ($indexName, $unique): void {
            if ($unique) {
                $table->dropUnique($indexName);
            } else {
                $table->dropIndex($indexName);
            }
        });
    }
};
