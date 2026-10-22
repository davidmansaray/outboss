<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REFERENCES = [
        'campaign_recipient_id' => 'campaign_recipients',
        'message_id' => 'messages',
        'send_attempt_id' => 'send_attempts',
    ];

    public function up(): void
    {
        // Refuse historical tenant drift before any DDL; never rewrite audit
        // ownership or silently remove a retained event to make migration pass.
        foreach (self::REFERENCES as $column => $parent) {
            if (DB::table('execution_action_events as events')
                ->join($parent.' as parent', 'parent.id', '=', 'events.'.$column)
                ->whereColumn('events.workspace_id', '!=', 'parent.workspace_id')->exists()) {
                throw new RuntimeException('Execution action event tenant references require investigation before migration.');
            }
        }
        Schema::table('execution_action_events', function (Blueprint $table): void {
            foreach (self::REFERENCES as $column => $parent) {
                $table->foreign(['workspace_id', $column], 'action_events_ws_'.$column.'_fk')
                    ->references(['workspace_id', 'id'])->on($parent)->restrictOnDelete();
            }
        });
        Schema::table('execution_action_events', function (Blueprint $table): void {
            foreach (self::REFERENCES as $column => $_parent) {
                $table->dropForeign([$column]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('execution_action_events', function (Blueprint $table): void {
            foreach (self::REFERENCES as $column => $parent) {
                $table->foreign($column)->references('id')->on($parent)->restrictOnDelete();
            }
        });
        Schema::table('execution_action_events', function (Blueprint $table): void {
            foreach (self::REFERENCES as $column => $_parent) {
                $table->dropForeign('action_events_ws_'.$column.'_fk');
            }
        });
    }
};
