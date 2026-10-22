<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('current_version_id')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamp('execution_paused_at')->nullable();
            $table->string('execution_paused_reason', 500)->nullable();
            $table->foreignId('execution_paused_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('workspace_id', 'outreach_policies_workspace_unique');
            $table->unique(['workspace_id', 'id'], 'outreach_policies_workspace_id_unique');
            $table->index(['workspace_id', 'created_at'], 'outreach_policies_workspace_created_index');
        });

        Schema::create('outreach_policy_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('outreach_policy_id');
            $table->unsignedInteger('version');
            $table->unsignedInteger('maximum_people');
            $table->unsignedInteger('maximum_accounts');
            $table->unsignedInteger('maximum_messages');
            $table->unsignedBigInteger('maximum_ai_cost_micros');
            $table->unsignedInteger('approval_ttl_hours');
            $table->json('send_window');
            $table->json('allowed_source_purposes');
            $table->json('qualification_constraints');
            $table->json('evidence_constraints');
            $table->json('offer_constraints');
            $table->json('fulfilment_constraints');
            $table->string('schema_version', 40)->default('outreach_policy_v1');
            $table->char('content_hash', 64);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'outreach_policy_id'], 'outreach_policy_versions_workspace_policy_fk')
                ->references(['workspace_id', 'id'])->on('outreach_policies')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'outreach_policy_versions_workspace_id_unique');
            $table->unique(['workspace_id', 'outreach_policy_id', 'version'], 'outreach_policy_versions_policy_version_unique');
            $table->unique(['workspace_id', 'outreach_policy_id', 'content_hash'], 'outreach_policy_versions_content_unique');
            $table->index(['workspace_id', 'created_at'], 'outreach_policy_versions_workspace_created_index');
        });

        Schema::table('outreach_policies', function (Blueprint $table): void {
            $table->foreign(['workspace_id', 'current_version_id'], 'outreach_policies_current_version_fk')
                ->references(['workspace_id', 'id'])->on('outreach_policy_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outreach_policies', function (Blueprint $table): void {
            $table->dropForeign('outreach_policies_current_version_fk');
        });
        Schema::dropIfExists('outreach_policy_versions');
        Schema::dropIfExists('outreach_policies');
    }
};
