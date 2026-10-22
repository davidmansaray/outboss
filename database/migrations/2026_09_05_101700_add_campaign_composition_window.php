<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_compositions', function (Blueprint $table): void {
            $table->dateTime('sending_starts_at')->nullable();
            $table->dateTime('sending_ends_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('campaign_compositions')->whereNotNull('sending_starts_at')->orWhereNotNull('sending_ends_at')->exists()) {
            throw new RuntimeException('Recorded campaign sending windows cannot be discarded by rollback.');
        }
        Schema::table('campaign_compositions', function (Blueprint $table): void {
            $table->dropColumn(['sending_starts_at', 'sending_ends_at']);
        });
    }
};
