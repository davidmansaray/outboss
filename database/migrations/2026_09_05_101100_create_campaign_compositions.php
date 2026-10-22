<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('objective_goal_versions', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'id'], 'goal_versions_workspace_id_unique');
        });
        Schema::create('campaign_compositions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedInteger('revision');
            $table->uuid('objective_goal_version_id')->nullable();
            $table->uuid('offer_version_id')->nullable();
            $table->uuid('operation_id');
            $table->string('request_hash', 64);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['workspace_id', 'campaign_id'], 'composition_campaign_fk')
                ->references(['workspace_id', 'id'])->on('campaigns')->restrictOnDelete();
            $table->foreign(['workspace_id', 'objective_goal_version_id'], 'composition_goal_fk')
                ->references(['workspace_id', 'id'])->on('objective_goal_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'offer_version_id'], 'composition_offer_fk')
                ->references(['workspace_id', 'id'])->on('offer_versions')->restrictOnDelete();
            $table->unique(['workspace_id', 'campaign_id', 'revision'], 'composition_revision_unique');
            $table->unique(['workspace_id', 'campaign_id', 'operation_id'], 'composition_operation_unique');
            $table->unique(['workspace_id', 'campaign_id', 'id'], 'composition_owner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_compositions');
        Schema::table('objective_goal_versions', function (Blueprint $table): void {
            $table->dropUnique('goal_versions_workspace_id_unique');
        });
    }
};
