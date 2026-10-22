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

    /** @var array<string, string> */
    private const ROLLBACK_INDEXES = [
        'mailboxes' => 'mailboxes_workspace_id_index',
        'campaigns' => 'campaigns_workspace_id_index',
        'threads' => 'threads_workspace_id_index',
        'send_attempts' => 'send_attempts_workspace_id_index',
        'suppression_entries' => 'suppression_entries_workspace_id_index',
    ];

    public function up(): void
    {
        foreach (self::SCOPED_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('workspace_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('workspaces')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::SCOPED_TABLES) as $tableName) {
            $rollbackIndex = self::ROLLBACK_INDEXES[$tableName] ?? null;

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $rollbackIndex): void {
                $table->dropForeign(['workspace_id']);

                if ($rollbackIndex !== null && Schema::hasIndex($tableName, $rollbackIndex)) {
                    $table->dropIndex($rollbackIndex);
                }

                $table->dropColumn('workspace_id');
            });
        }
    }
};
