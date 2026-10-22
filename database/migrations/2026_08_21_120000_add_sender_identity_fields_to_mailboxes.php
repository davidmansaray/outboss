<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table): void {
            // Null means the provider has no sender-identity discovery (or this
            // is a legacy row). An empty array means discovery ran but found no
            // usable identity.
            $table->json('sender_identities')->nullable()->after('provider_metadata');
            $table->string('selected_sender_identity_key', 64)->nullable()->after('sender_identities');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table): void {
            $table->dropColumn(['sender_identities', 'selected_sender_identity_key']);
        });
    }
};
