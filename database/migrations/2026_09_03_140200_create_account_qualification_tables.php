<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qualification_rubrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('state', 24)->default('draft');
            $table->uuid('current_version_id')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'qualification_rubrics_workspace_id_unique');
            $table->unique(['workspace_id', 'name'], 'qualification_rubrics_workspace_name_unique');
            $table->index(['workspace_id', 'state'], 'qualification_rubrics_workspace_state_index');
        });

        Schema::create('qualification_rubric_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('rubric_id');
            $table->unsignedInteger('version');
            $table->unsignedInteger('schema_version')->default(1);
            $table->uuid('gtm_profile_version_id')->nullable();
            $table->uuid('offer_version_id')->nullable();
            $table->json('dimensions');
            $table->json('required_facts');
            $table->json('disqualifiers');
            $table->json('confidence_bands');
            $table->json('sensitive_predicates');
            $table->json('decision_rules');
            $table->char('content_hash', 64);
            $table->json('actor_snapshot');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'rubric_id'], 'qualification_rubric_versions_workspace_rubric_fk')
                ->references(['workspace_id', 'id'])->on('qualification_rubrics')->restrictOnDelete();
            $table->foreign('gtm_profile_version_id', 'qualification_rubric_versions_gtm_version_fk')
                ->references('id')->on('gtm_profile_versions')->restrictOnDelete();
            $table->foreign('offer_version_id', 'qualification_rubric_versions_offer_version_fk')
                ->references('id')->on('offer_versions')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'qualification_rubric_versions_workspace_id_unique');
            $table->unique(['workspace_id', 'rubric_id', 'version'], 'qualification_rubric_versions_rubric_version_unique');
            $table->index(['workspace_id', 'rubric_id', 'created_at'], 'qualification_rubric_versions_created_index');
        });

        Schema::table('qualification_rubrics', function (Blueprint $table): void {
            $table->foreign(['workspace_id', 'current_version_id'], 'qualification_rubrics_current_version_fk')
                ->references(['workspace_id', 'id'])->on('qualification_rubric_versions')->restrictOnDelete();
        });

        Schema::create('qualification_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('account_id');
            $table->uuid('research_run_id');
            $table->uuid('rubric_version_id');
            $table->uuid('gtm_profile_version_id')->nullable();
            $table->uuid('offer_version_id')->nullable();
            $table->uuid('prior_decision_id')->nullable();
            $table->char('input_hash', 64);
            $table->string('outcome', 32);
            $table->decimal('confidence', 5, 4)->default(0);
            $table->string('calibration_band', 32)->nullable();
            $table->json('missing_facts');
            $table->json('disqualifiers');
            $table->text('explanation');
            $table->string('model_version', 120)->nullable();
            $table->string('prompt_version', 80)->nullable();
            $table->string('policy_version', 80)->nullable();
            $table->string('catalogue_version', 80)->nullable();
            $table->uuid('provider_operation_id')->nullable();
            $table->unsignedBigInteger('provider_cost_micros')->default(0);
            $table->string('review_state', 32)->default('pending');
            $table->char('content_hash', 64);
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'account_id'], 'qualification_decisions_workspace_account_fk')
                ->references(['workspace_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['workspace_id', 'research_run_id'], 'qualification_decisions_workspace_run_fk')
                ->references(['workspace_id', 'id'])->on('account_research_runs')->restrictOnDelete();
            $table->foreign(['workspace_id', 'rubric_version_id'], 'qualification_decisions_workspace_rubric_version_fk')
                ->references(['workspace_id', 'id'])->on('qualification_rubric_versions')->restrictOnDelete();
            $table->foreign('gtm_profile_version_id', 'qualification_decisions_gtm_version_fk')
                ->references('id')->on('gtm_profile_versions')->restrictOnDelete();
            $table->foreign('offer_version_id', 'qualification_decisions_offer_version_fk')
                ->references('id')->on('offer_versions')->restrictOnDelete();
            $table->foreign('provider_operation_id', 'qualification_decisions_provider_operation_fk')
                ->references('id')->on('ai_provider_operations')->nullOnDelete();
            $table->unique(['workspace_id', 'id'], 'qualification_decisions_workspace_id_unique');
            $table->unique(['workspace_id', 'research_run_id', 'rubric_version_id', 'input_hash'], 'qualification_decisions_input_unique');
            $table->index(['workspace_id', 'account_id', 'created_at'], 'qualification_decisions_account_created_index');
            $table->index(['workspace_id', 'outcome', 'review_state'], 'qualification_decisions_outcome_index');
        });

        Schema::table('qualification_decisions', function (Blueprint $table): void {
            $table->foreign(['workspace_id', 'prior_decision_id'], 'qualification_decisions_prior_fk')
                ->references(['workspace_id', 'id'])->on('qualification_decisions')->restrictOnDelete();
        });

        Schema::create('qualification_decision_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('decision_id');
            $table->uuid('claim_id');
            $table->string('role', 24);
            $table->timestamps();

            $table->foreign(['workspace_id', 'decision_id'], 'qualification_decision_claims_workspace_decision_fk')
                ->references(['workspace_id', 'id'])->on('qualification_decisions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'claim_id'], 'qualification_decision_claims_workspace_claim_fk')
                ->references(['workspace_id', 'id'])->on('research_claims')->restrictOnDelete();
            $table->unique(['workspace_id', 'decision_id', 'claim_id', 'role'], 'qualification_decision_claims_unique');
            $table->index(['workspace_id', 'claim_id'], 'qualification_decision_claims_claim_index');
        });

        Schema::create('reason_to_contact_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('account_id');
            $table->uuid('qualification_decision_id');
            $table->uuid('successor_id')->nullable();
            $table->text('why_account')->nullable();
            $table->text('why_offer')->nullable();
            $table->text('why_now')->nullable();
            $table->text('factual_basis')->nullable();
            $table->text('internal_rationale')->nullable();
            $table->decimal('confidence', 5, 4)->default(0);
            $table->timestamp('fresh_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('safe_for_message')->default(false);
            $table->boolean('sensitive_inference')->default(false);
            $table->boolean('prohibited_inference')->default(false);
            $table->char('content_hash', 64);
            $table->timestamps();

            $table->foreign(['workspace_id', 'account_id'], 'reason_contact_workspace_account_fk')
                ->references(['workspace_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['workspace_id', 'qualification_decision_id'], 'reason_contact_workspace_decision_fk')
                ->references(['workspace_id', 'id'])->on('qualification_decisions')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'reason_contact_workspace_id_unique');
            $table->index(['workspace_id', 'account_id', 'created_at'], 'reason_contact_account_created_index');
        });

        Schema::table('reason_to_contact_versions', function (Blueprint $table): void {
            $table->foreign(['workspace_id', 'successor_id'], 'reason_contact_successor_fk')
                ->references(['workspace_id', 'id'])->on('reason_to_contact_versions')->restrictOnDelete();
        });

        Schema::create('reason_to_contact_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('reason_to_contact_version_id');
            $table->uuid('claim_id');
            $table->string('role', 24);
            $table->timestamps();

            $table->foreign(['workspace_id', 'reason_to_contact_version_id'], 'reason_contact_claims_workspace_reason_fk')
                ->references(['workspace_id', 'id'])->on('reason_to_contact_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'claim_id'], 'reason_contact_claims_workspace_claim_fk')
                ->references(['workspace_id', 'id'])->on('research_claims')->restrictOnDelete();
            $table->unique(['workspace_id', 'reason_to_contact_version_id', 'claim_id', 'role'], 'reason_contact_claims_unique');
        });

        Schema::create('qualification_review_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('account_id');
            $table->uuid('decision_id');
            $table->string('action', 32);
            $table->json('actor_snapshot');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 2000)->nullable();
            $table->string('prior_review_state', 32)->nullable();
            $table->string('projected_review_state', 32)->nullable();
            $table->uuid('successor_decision_id')->nullable();
            $table->uuid('successor_run_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'account_id'], 'qualification_review_workspace_account_fk')
                ->references(['workspace_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['workspace_id', 'decision_id'], 'qualification_review_workspace_decision_fk')
                ->references(['workspace_id', 'id'])->on('qualification_decisions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'successor_decision_id'], 'qualification_review_successor_decision_fk')
                ->references(['workspace_id', 'id'])->on('qualification_decisions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'successor_run_id'], 'qualification_review_successor_run_fk')
                ->references(['workspace_id', 'id'])->on('account_research_runs')->restrictOnDelete();
            $table->index(['workspace_id', 'account_id', 'created_at'], 'qualification_review_account_created_index');
            $table->index(['workspace_id', 'decision_id', 'created_at'], 'qualification_review_decision_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('qualification_rubrics', function (Blueprint $table): void {
            $table->dropForeign('qualification_rubrics_current_version_fk');
        });
        Schema::dropIfExists('qualification_review_events');
        Schema::dropIfExists('reason_to_contact_claims');
        Schema::dropIfExists('reason_to_contact_versions');
        Schema::dropIfExists('qualification_decision_claims');
        Schema::dropIfExists('qualification_decisions');
        Schema::dropIfExists('qualification_rubric_versions');
        Schema::dropIfExists('qualification_rubrics');
    }
};
