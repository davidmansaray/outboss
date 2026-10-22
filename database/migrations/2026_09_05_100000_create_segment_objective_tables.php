<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('current_version_id')->nullable();
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'segments_workspace_id_unique');
            $table->index(['workspace_id', 'status', 'created_at'], 'segments_workspace_status_created_index');
        });

        Schema::create('segment_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('segment_id');
            $table->unsignedInteger('version');
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->json('criteria');
            $table->string('criteria_schema_version', 40)->default('segment_criteria_v1');
            $table->string('missing_data_policy', 24)->default('uncertain');
            $table->json('buyer_roles');
            $table->json('required_qualification_outcomes');
            $table->string('evaluator_version', 40)->default('segment_evaluator_v1');
            $table->char('content_hash', 64);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'segment_id'], 'segment_versions_workspace_segment_fk')
                ->references(['workspace_id', 'id'])->on('segments')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'segment_versions_workspace_id_unique');
            $table->unique(['workspace_id', 'segment_id', 'version'], 'segment_versions_segment_version_unique');
            $table->unique(['workspace_id', 'segment_id', 'content_hash'], 'segment_versions_content_unique');
            $table->index(['workspace_id', 'created_at'], 'segment_versions_workspace_created_index');
        });

        Schema::table('segments', function (Blueprint $table): void {
            $table->foreign(['workspace_id', 'current_version_id'], 'segments_current_version_fk')
                ->references(['workspace_id', 'id'])->on('segment_versions')->restrictOnDelete();
        });

        Schema::create('audience_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('segment_version_id');
            $table->uuid('objective_id')->nullable();
            $table->string('purpose', 40)->default('objective_execution');
            $table->string('status', 24)->default('frozen');
            $table->string('evaluator_version', 40)->default('segment_evaluator_v1');
            $table->char('criteria_hash', 64);
            $table->char('evaluation_hash', 64);
            $table->unsignedInteger('included_people_count')->default(0);
            $table->unsignedInteger('excluded_people_count')->default(0);
            $table->unsignedInteger('uncertain_people_count')->default(0);
            $table->unsignedInteger('suppressed_people_count')->default(0);
            $table->unsignedInteger('included_accounts_count')->default(0);
            $table->unsignedInteger('excluded_accounts_count')->default(0);
            $table->unsignedInteger('uncertain_accounts_count')->default(0);
            $table->unsignedInteger('suppressed_accounts_count')->default(0);
            $table->json('source_rights_snapshot');
            $table->foreignId('frozen_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('frozen_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'segment_version_id'], 'audience_snapshots_workspace_segment_version_fk')
                ->references(['workspace_id', 'id'])->on('segment_versions')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'audience_snapshots_workspace_id_unique');
            $table->unique(['workspace_id', 'segment_version_id', 'evaluation_hash'], 'audience_snapshots_freeze_unique');
            $table->index(['workspace_id', 'created_at'], 'audience_snapshots_workspace_created_index');
        });

        Schema::create('audience_snapshot_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('audience_snapshot_id');
            $table->foreignId('account_id')->nullable();
            $table->foreignId('person_id')->nullable();
            $table->string('result', 24);
            $table->uuid('qualification_decision_id')->nullable();
            $table->uuid('reason_to_contact_version_id')->nullable();
            $table->json('criterion_results');
            $table->json('inclusion_reasons');
            $table->json('exclusion_reasons');
            $table->json('missing_fields');
            $table->json('suppression_snapshot');
            $table->json('evidence_manifest');
            $table->char('qualification_content_hash', 64)->nullable();
            $table->char('reason_content_hash', 64)->nullable();
            $table->char('source_lineage_hash', 64);
            $table->char('member_hash', 64);
            $table->timestamp('evidence_fresh_until')->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'audience_snapshot_id'], 'snapshot_members_workspace_snapshot_fk')
                ->references(['workspace_id', 'id'])->on('audience_snapshots')->restrictOnDelete();
            $table->foreign(['workspace_id', 'account_id'], 'snapshot_members_workspace_account_fk')
                ->references(['workspace_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['workspace_id', 'person_id'], 'snapshot_members_workspace_person_fk')
                ->references(['workspace_id', 'id'])->on('people')->restrictOnDelete();
            $table->foreign(['workspace_id', 'qualification_decision_id'], 'snapshot_members_workspace_qualification_fk')
                ->references(['workspace_id', 'id'])->on('qualification_decisions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'reason_to_contact_version_id'], 'snapshot_members_workspace_reason_fk')
                ->references(['workspace_id', 'id'])->on('reason_to_contact_versions')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'snapshot_members_workspace_id_unique');
            $table->unique(['workspace_id', 'audience_snapshot_id', 'person_id'], 'snapshot_members_snapshot_person_unique');
            $table->index(['workspace_id', 'audience_snapshot_id', 'result'], 'snapshot_members_snapshot_result_index');
            $table->index(['workspace_id', 'created_at'], 'snapshot_members_workspace_created_index');
        });

        Schema::create('audience_snapshot_member_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('audience_snapshot_id');
            $table->uuid('audience_snapshot_member_id');
            $table->uuid('prospect_source_id')->nullable();
            $table->uuid('prospect_source_version_id')->nullable();
            $table->uuid('source_observation_id')->nullable();
            $table->unsignedBigInteger('account_source_membership_id')->nullable();
            $table->unsignedBigInteger('person_source_membership_id')->nullable();
            $table->char('policy_hash', 64);
            $table->string('rights_result', 32);
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'audience_snapshot_id'], 'snapshot_sources_workspace_snapshot_fk')
                ->references(['workspace_id', 'id'])->on('audience_snapshots')->restrictOnDelete();
            $table->foreign(['workspace_id', 'audience_snapshot_member_id'], 'snapshot_sources_workspace_member_fk')
                ->references(['workspace_id', 'id'])->on('audience_snapshot_members')->restrictOnDelete();
            $table->foreign('prospect_source_id', 'snapshot_sources_source_fk')->references('id')->on('prospect_sources')->restrictOnDelete();
            $table->foreign('prospect_source_version_id', 'snapshot_sources_source_version_fk')->references('id')->on('prospect_source_versions')->restrictOnDelete();
            $table->foreign('source_observation_id', 'snapshot_sources_observation_fk')->references('id')->on('source_observations')->restrictOnDelete();
            $table->foreign('account_source_membership_id', 'snapshot_sources_account_membership_fk')->references('id')->on('account_source_memberships')->restrictOnDelete();
            $table->foreign('person_source_membership_id', 'snapshot_sources_person_membership_fk')->references('id')->on('person_source_memberships')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'snapshot_sources_workspace_id_unique');
            $table->unique(['workspace_id', 'audience_snapshot_member_id', 'prospect_source_id'], 'snapshot_sources_member_source_unique');
            $table->index(['workspace_id', 'created_at'], 'snapshot_sources_workspace_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_snapshot_member_sources');
        Schema::dropIfExists('audience_snapshot_members');
        Schema::dropIfExists('audience_snapshots');
        Schema::table('segments', function (Blueprint $table): void {
            $table->dropForeign('segments_current_version_fk');
        });
        Schema::dropIfExists('segment_versions');
        Schema::dropIfExists('segments');
    }
};
