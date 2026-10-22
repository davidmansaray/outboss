<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_authority_scopes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->json('authority');
            $table->string('content_hash', 64);
            $table->uuid('operation_id');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['workspace_id', 'id'], 'execution_scope_workspace_unique');
            $table->unique(['workspace_id', 'operation_id'], 'execution_scope_operation_unique');
        });
        Schema::table('campaign_compositions', function (Blueprint $table): void {
            $table->uuid('execution_authority_scope_id')->nullable();
            $table->unsignedInteger('target_contribution_count')->nullable();
            $table->foreign(['workspace_id', 'execution_authority_scope_id'], 'composition_execution_scope_fk')
                ->references(['workspace_id', 'id'])->on('execution_authority_scopes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('execution_authority_scopes')->exists()
            || DB::table('campaign_compositions')->whereNotNull('target_contribution_count')->exists()) {
            throw new RuntimeException('Recorded execution authority or campaign targets cannot be discarded by rollback.');
        }
        Schema::table('campaign_compositions', function (Blueprint $table): void {
            $table->dropForeign('composition_execution_scope_fk');
            $table->dropColumn(['execution_authority_scope_id', 'target_contribution_count']);
        });
        Schema::dropIfExists('execution_authority_scopes');
    }
};
