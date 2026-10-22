<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_authority_revocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('workspace_id');
            $table->uuid('execution_authority_scope_id');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['workspace_id', 'execution_authority_scope_id'], 'execution_scope_revocation_unique');
            $table->foreign(['workspace_id', 'execution_authority_scope_id'], 'execution_scope_revocation_fk')
                ->references(['workspace_id', 'id'])->on('execution_authority_scopes')->restrictOnDelete();
        });
        Schema::create('execution_authority_legacy_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('workspace_id');
            $table->uuid('objective_id');
            $table->uuid('source_goal_version_id');
            $table->uuid('execution_authority_scope_id');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['workspace_id', 'objective_id'], 'legacy_authority_objective_unique');
            $table->unique(['workspace_id', 'execution_authority_scope_id'], 'legacy_authority_scope_unique');
            $table->foreign(['workspace_id', 'objective_id', 'source_goal_version_id'], 'legacy_authority_goal_fk')
                ->references(['workspace_id', 'objective_id', 'id'])->on('objective_goal_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'execution_authority_scope_id'], 'legacy_authority_scope_fk')
                ->references(['workspace_id', 'id'])->on('execution_authority_scopes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('execution_authority_legacy_mappings')->exists() || DB::table('execution_authority_revocations')->exists()) {
            throw new RuntimeException('Cannot discard the mapping that preserves historical execution commitments.');
        }
        Schema::dropIfExists('execution_authority_legacy_mappings');
        Schema::dropIfExists('execution_authority_revocations');
    }
};
