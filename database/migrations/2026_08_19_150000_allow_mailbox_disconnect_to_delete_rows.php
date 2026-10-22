<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->nullableMailboxForeign('threads');
        $this->nullableMailboxForeign('messages');
        $this->nullableMailboxForeign('send_attempts');
        $this->nullableMailboxForeign('campaign_recipients');
        $this->replaceMailboxForeign('mailbox_sync_states', 'cascade');
        $this->replaceMailboxForeign('campaign_mailbox', 'cascade');

        $disconnected = DB::table('mailboxes')->where('status', 'disconnected')->pluck('id');

        if ($disconnected->isNotEmpty()) {
            DB::table('mailbox_sync_states')->whereIn('mailbox_id', $disconnected)->delete();
            DB::table('campaign_mailbox')->whereIn('mailbox_id', $disconnected)->delete();
            DB::table('mailboxes')->whereIn('id', $disconnected)->delete();
        }
    }

    public function down(): void
    {
        $this->replaceMailboxForeign('threads', 'restrict', nullable: false);
        $this->replaceMailboxForeign('messages', 'restrict', nullable: false);
        $this->replaceMailboxForeign('send_attempts', 'restrict', nullable: false);
        $this->replaceMailboxForeign('campaign_recipients', 'restrict', nullable: false);
        $this->replaceMailboxForeign('mailbox_sync_states', 'restrict', nullable: false);
        $this->replaceMailboxForeign('campaign_mailbox', 'restrict', nullable: false);
    }

    private function nullableMailboxForeign(string $table): void
    {
        $this->replaceMailboxForeign($table, 'set null', nullable: true);
    }

    private function replaceMailboxForeign(string $table, string $onDelete, bool $nullable = false): void
    {
        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropForeign(['mailbox_id']);
        });

        Schema::table($table, function (Blueprint $blueprint) use ($onDelete, $nullable) {
            $blueprint->unsignedBigInteger('mailbox_id')->nullable($nullable)->change();

            $foreign = $blueprint->foreign('mailbox_id')->references('id')->on('mailboxes');

            match ($onDelete) {
                'cascade' => $foreign->cascadeOnDelete(),
                'set null' => $foreign->nullOnDelete(),
                default => $foreign->restrictOnDelete(),
            };
        });
    }
};
