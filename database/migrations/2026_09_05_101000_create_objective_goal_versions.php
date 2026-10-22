<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('objective_goal_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->uuid('objective_id');
            $table->unsignedInteger('version');
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('measurement', 80);
            $table->unsignedInteger('target_count');
            $table->date('deadline')->nullable();
            $table->string('creation_key', 80)->nullable();
            $table->string('content_hash', 64);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['workspace_id', 'objective_id'], 'goal_versions_objective_fk')
                ->references(['workspace_id', 'id'])->on('objectives')->restrictOnDelete();
            $table->unique(['workspace_id', 'objective_id', 'version'], 'goal_versions_revision_unique');
            $table->unique(['workspace_id', 'creation_key'], 'goal_versions_creation_unique');
            $table->unique(['workspace_id', 'objective_id', 'id'], 'goal_versions_owner_unique');
        });
        Schema::table('objectives', function (Blueprint $table): void {
            $table->uuid('current_goal_version_id')->nullable();
            $table->foreign(['workspace_id', 'id', 'current_goal_version_id'], 'objectives_current_goal_fk')
                ->references(['workspace_id', 'objective_id', 'id'])->on('objective_goal_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('objectives', function (Blueprint $table): void {
            $table->dropForeign('objectives_current_goal_fk');
            $table->dropColumn('current_goal_version_id');
        });
        Schema::dropIfExists('objective_goal_versions');
    }
};
