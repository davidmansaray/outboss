<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('objective_goal_versions', function (Blueprint $table): void {
            $table->unsignedInteger('target_count')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('objective_goal_versions')->whereNull('target_count')->exists()) {
            throw new RuntimeException('Evergreen Objectives exist; rollback cannot invent target counts.');
        }
        Schema::table('objective_goal_versions', function (Blueprint $table): void {
            $table->unsignedInteger('target_count')->nullable(false)->change();
        });
    }
};
