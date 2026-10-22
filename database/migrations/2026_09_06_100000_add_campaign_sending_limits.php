<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite rebuilds a table for Schema::table changes. Rebuilding
            // campaigns would invalidate the frozen-audience triggers that
            // reference it, so add the columns in place and enforce the
            // workspace-scoped scope edge with equivalent triggers.
            DB::statement('ALTER TABLE campaigns ADD COLUMN daily_send_limit INTEGER');
            DB::statement('ALTER TABLE campaigns ADD COLUMN automatic_sending_scope_id TEXT');
            $this->createSqliteScopeTriggers();

            return;
        }

        Schema::table('campaigns', function (Blueprint $table): void {
            // Null preserves the legacy "use the inbox budget" behavior. A
            // non-null value is the campaign-wide requested messages/day.
            $table->unsignedInteger('daily_send_limit')->nullable();
            // This marker is only for the automatic allowance owned by this
            // campaign. Manual/shared composition scopes remain independent.
            $table->uuid('automatic_sending_scope_id')->nullable();
            $table->foreign(
                ['workspace_id', 'automatic_sending_scope_id'],
                'campaign_automatic_sending_scope_fk',
            )->references(['workspace_id', 'id'])->on('execution_authority_scopes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('campaigns')->whereNotNull('daily_send_limit')->exists()
            || DB::table('campaigns')->whereNotNull('automatic_sending_scope_id')->exists()) {
            throw new RuntimeException('Campaign sending limits and automatic allowance markers cannot be discarded by rollback.');
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS campaign_automatic_sending_scope_insert_guard');
            DB::statement('DROP TRIGGER IF EXISTS campaign_automatic_sending_scope_update_guard');
            DB::statement('ALTER TABLE campaigns DROP COLUMN automatic_sending_scope_id');
            DB::statement('ALTER TABLE campaigns DROP COLUMN daily_send_limit');

            return;
        }

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropForeign('campaign_automatic_sending_scope_fk');
            $table->dropColumn(['daily_send_limit', 'automatic_sending_scope_id']);
        });
    }

    private function createSqliteScopeTriggers(): void
    {
        DB::statement(<<<'SQL'
CREATE TRIGGER campaign_automatic_sending_scope_insert_guard
BEFORE INSERT ON campaigns
FOR EACH ROW
WHEN NEW.automatic_sending_scope_id IS NOT NULL
 AND NOT EXISTS (
    SELECT 1
    FROM execution_authority_scopes scope
    WHERE scope.id = NEW.automatic_sending_scope_id
      AND scope.workspace_id = NEW.workspace_id
)
BEGIN
    SELECT RAISE(ABORT, 'Campaign automatic sending scope belongs to another workspace');
END
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER campaign_automatic_sending_scope_update_guard
BEFORE UPDATE ON campaigns
FOR EACH ROW
WHEN (NEW.workspace_id IS NOT OLD.workspace_id
   OR NEW.automatic_sending_scope_id IS NOT OLD.automatic_sending_scope_id)
 AND NEW.automatic_sending_scope_id IS NOT NULL
 AND NOT EXISTS (
    SELECT 1
    FROM execution_authority_scopes scope
    WHERE scope.id = NEW.automatic_sending_scope_id
      AND scope.workspace_id = NEW.workspace_id
)
BEGIN
    SELECT RAISE(ABORT, 'Campaign automatic sending scope belongs to another workspace');
END
SQL);
    }
};
