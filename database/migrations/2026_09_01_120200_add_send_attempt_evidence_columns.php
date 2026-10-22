<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These fields retain provider-reaching and ambiguous/error history
        // after reconciliation settles the mutable workflow status.
        Schema::table('send_attempts', function (Blueprint $table): void {
            $table->timestamp('provider_attempted_at')->nullable()->after('lease_acquired_at');
            $table->unsignedInteger('provider_unknown_count')->default(0)->after('attempt_count');
            $table->unsignedInteger('provider_manual_review_count')->default(0)->after('provider_unknown_count');
            $table->unsignedInteger('provider_error_count')->default(0)->after('provider_manual_review_count');
        });
    }

    public function down(): void
    {
        Schema::table('send_attempts', function (Blueprint $table): void {
            $table->dropColumn([
                'provider_attempted_at',
                'provider_unknown_count',
                'provider_manual_review_count',
                'provider_error_count',
            ]);
        });
    }
};
