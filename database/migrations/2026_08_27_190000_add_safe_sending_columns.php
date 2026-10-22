<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('daily_send_cap')->nullable();
            $table->timestamp('next_send_slot_at')->nullable();
            $table->timestamp('sending_paused_at')->nullable();
            $table->string('sending_paused_reason')->nullable();
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->boolean('open_tracking_enabled')->default(false);
        });

        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->string('email_domain')->nullable();
            $table->uuid('unsubscribe_token')->nullable()->unique();
            $table->index(['workspace_id', 'email_domain']);
        });

        DB::table('campaign_recipients')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $updates = [];

                    if ($row->email_domain === null) {
                        $email = strtolower(trim((string) $row->email_normalized));
                        $at = strrpos($email, '@');
                        $updates['email_domain'] = $at === false ? null : (substr($email, $at + 1) ?: null);
                    }

                    if ($row->unsubscribe_token === null) {
                        $updates['unsubscribe_token'] = (string) Str::uuid();
                    }

                    if ($updates !== []) {
                        DB::table('campaign_recipients')->where('id', $row->id)->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'email_domain']);
            $table->dropColumn(['email_domain', 'unsubscribe_token']);
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('open_tracking_enabled');
        });

        Schema::table('mailboxes', function (Blueprint $table): void {
            $table->dropColumn([
                'daily_send_cap',
                'next_send_slot_at',
                'sending_paused_at',
                'sending_paused_reason',
            ]);
        });
    }
};
