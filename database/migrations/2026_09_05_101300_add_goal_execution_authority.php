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
            $table->uuid('conversion_definition_version_id')->nullable();
            $table->json('execution_authority')->nullable();
            $table->foreign(['workspace_id', 'conversion_definition_version_id'], 'goal_measurement_version_fk')
                ->references(['workspace_id', 'id'])->on('conversion_definition_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('objective_goal_versions')->whereNotNull('execution_authority')->orWhereNotNull('conversion_definition_version_id')->exists()) {
            throw new RuntimeException('Cannot discard recorded Objective authority during rollback.');
        }
        Schema::table('objective_goal_versions', function (Blueprint $table): void {
            $table->dropForeign('goal_measurement_version_fk');
            $table->dropColumn(['conversion_definition_version_id', 'execution_authority']);
        });
    }
};
