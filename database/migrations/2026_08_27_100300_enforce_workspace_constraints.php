<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const SCOPED_TABLES = [
        'provider_connections',
        'mailboxes',
        'campaigns',
        'campaign_recipients',
        'recipient_imports',
        'threads',
        'messages',
        'send_attempts',
        'suppression_entries',
    ];

    public function up(): void
    {
        foreach (self::SCOPED_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('workspace_id')->nullable(false)->change();
            });
        }

        Schema::table('mailboxes', function (Blueprint $table): void {
            $this->addIndexIfMissing(
                $table,
                'mailboxes',
                ['workspace_id', 'status'],
                'mailboxes_workspace_id_status_index',
            );
            $this->addIndexIfMissing(
                $table,
                'mailboxes',
                ['workspace_id', 'email_address'],
                'mailboxes_workspace_id_email_address_index',
            );
            // Keep a user_id-leading index for the existing users FK after
            // replacing the owner-oriented lookup indexes below. InnoDB
            // requires a supporting index for every foreign key.
            $this->addIndexIfMissing($table, 'mailboxes', 'user_id', 'mailboxes_user_id_index');
            $this->dropIndexIfPresent($table, 'mailboxes', 'mailboxes_user_id_status_index');
            $this->dropIndexIfPresent($table, 'mailboxes', 'mailboxes_user_id_email_address_index');
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $this->addIndexIfMissing(
                $table,
                'campaigns',
                ['workspace_id', 'status'],
                'campaigns_workspace_id_status_index',
            );
            $this->addIndexIfMissing($table, 'campaigns', 'user_id', 'campaigns_user_id_index');
            $this->dropIndexIfPresent($table, 'campaigns', 'campaigns_user_id_status_index');
        });

        Schema::table('threads', function (Blueprint $table): void {
            $this->addIndexIfMissing(
                $table,
                'threads',
                ['workspace_id', 'local_state', 'last_message_at'],
                'threads_workspace_id_local_state_last_message_at_index',
            );
            $this->addIndexIfMissing($table, 'threads', 'user_id', 'threads_user_id_index');
            $this->dropIndexIfPresent(
                $table,
                'threads',
                'threads_user_id_local_state_last_message_at_index',
            );
        });

        Schema::table('suppression_entries', function (Blueprint $table): void {
            $this->addUniqueIfMissing(
                $table,
                'suppression_entries',
                ['workspace_id', 'email_normalized'],
                'suppression_entries_workspace_id_email_normalized_unique',
            );
            $this->addIndexIfMissing(
                $table,
                'suppression_entries',
                'user_id',
                'suppression_entries_user_id_index',
            );
            $this->dropUniqueIfPresent(
                $table,
                'suppression_entries',
                'suppression_entries_user_id_email_normalized_unique',
            );
        });

        Schema::table('send_attempts', function (Blueprint $table): void {
            $this->addUniqueIfMissing(
                $table,
                'send_attempts',
                ['workspace_id', 'kind', 'client_operation_id'],
                'send_attempts_workspace_id_kind_client_operation_id_unique',
            );
            $this->addIndexIfMissing($table, 'send_attempts', 'user_id', 'send_attempts_user_id_index');
            $this->dropUniqueIfPresent(
                $table,
                'send_attempts',
                'send_attempts_user_id_kind_client_operation_id_unique',
            );
        });
    }

    /**
     * Add a regular index only when a previous, partially completed migration
     * has not already created it.
     *
     * @param  array<int, string>|string  $columns
     */
    private function addIndexIfMissing(
        Blueprint $table,
        string $tableName,
        array|string $columns,
        string $indexName,
    ): void {
        if (! Schema::hasIndex($tableName, $indexName)) {
            $table->index($columns, $indexName);
        }
    }

    /**
     * Add a unique index only when it is not already present.
     *
     * @param  array<int, string>  $columns
     */
    private function addUniqueIfMissing(
        Blueprint $table,
        string $tableName,
        array $columns,
        string $indexName,
    ): void {
        if (! Schema::hasIndex($tableName, $indexName, 'unique')) {
            $table->unique($columns, $indexName);
        }
    }

    private function dropIndexIfPresent(Blueprint $table, string $tableName, string $indexName): void
    {
        if (Schema::hasIndex($tableName, $indexName)) {
            $table->dropIndex($indexName);
        }
    }

    private function dropUniqueIfPresent(Blueprint $table, string $tableName, string $indexName): void
    {
        if (Schema::hasIndex($tableName, $indexName, 'unique')) {
            $table->dropUnique($indexName);
        }
    }

    public function down(): void
    {
        Schema::table('send_attempts', function (Blueprint $table): void {
            $table->unique(
                ['user_id', 'kind', 'client_operation_id'],
                'send_attempts_user_id_kind_client_operation_id_unique',
            );
            $table->dropIndex('send_attempts_user_id_index');
            $table->index('workspace_id', 'send_attempts_workspace_id_index');
            $table->dropUnique('send_attempts_workspace_id_kind_client_operation_id_unique');
        });

        Schema::table('suppression_entries', function (Blueprint $table): void {
            $table->unique(
                ['user_id', 'email_normalized'],
                'suppression_entries_user_id_email_normalized_unique',
            );
            $table->dropIndex('suppression_entries_user_id_index');
            $table->index('workspace_id', 'suppression_entries_workspace_id_index');
            $table->dropUnique('suppression_entries_workspace_id_email_normalized_unique');
        });

        Schema::table('threads', function (Blueprint $table): void {
            $table->index(['user_id', 'local_state', 'last_message_at']);
            $table->dropIndex('threads_user_id_index');
            $table->index('workspace_id', 'threads_workspace_id_index');
            $table->dropIndex('threads_workspace_id_local_state_last_message_at_index');
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->index(['user_id', 'status']);
            $table->dropIndex('campaigns_user_id_index');
            $table->index('workspace_id', 'campaigns_workspace_id_index');
            $table->dropIndex('campaigns_workspace_id_status_index');
        });

        Schema::table('mailboxes', function (Blueprint $table): void {
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'email_address']);
            $table->dropIndex('mailboxes_user_id_index');
            $table->index('workspace_id', 'mailboxes_workspace_id_index');
            $table->dropIndex('mailboxes_workspace_id_status_index');
            $table->dropIndex('mailboxes_workspace_id_email_address_index');
        });

        foreach (array_reverse(self::SCOPED_TABLES) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('workspace_id')->nullable()->change();
            });
        }
    }
};
