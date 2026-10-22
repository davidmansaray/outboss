<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('objectives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('current_version_id')->nullable();
            $table->uuid('current_plan_id')->nullable();
            $table->string('status', 24)->default('draft');
            $table->string('status_reason_code', 80)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('last_actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'objectives_workspace_id_unique');
            $table->index(['workspace_id', 'status', 'created_at'], 'objectives_workspace_status_index');
        });

        // MySQL may use the original freeze unique as the supporting index
        // for the Segment Version FK. Give that FK a stable explicit index
        // before replacing the uniqueness boundary with Objective scope.
        Schema::table('audience_snapshots', function (Blueprint $table): void {
            $table->index(['workspace_id', 'segment_version_id'], 'snapshots_workspace_segment_index');
        });
        Schema::table('audience_snapshots', function (Blueprint $table): void {
            $table->dropUnique('audience_snapshots_freeze_unique');
            $table->foreign(['workspace_id', 'objective_id'], 'snapshots_workspace_objective_fk')
                ->references(['workspace_id', 'id'])->on('objectives')->restrictOnDelete();
            $table->unique(
                ['workspace_id', 'segment_version_id', 'evaluation_hash', 'objective_id'],
                'snapshots_objective_freeze_unique',
            );
        });

        Schema::create('objective_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('objective_id');
            $table->unsignedInteger('version');
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->uuid('conversion_definition_id');
            $table->uuid('conversion_definition_version_id');
            $table->uuid('segment_version_id');
            $table->uuid('audience_snapshot_id');
            $table->uuid('offer_id');
            $table->uuid('offer_version_id');
            $table->string('target_kind', 16);
            $table->unsignedInteger('target_count')->nullable();
            $table->decimal('target_value', 18, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('attribution_window_days');
            $table->unsignedInteger('maximum_people');
            $table->unsignedInteger('maximum_accounts');
            $table->unsignedInteger('maximum_messages');
            $table->unsignedBigInteger('maximum_ai_cost_micros');
            $table->json('required_qualification_outcomes');
            $table->decimal('minimum_qualification_confidence', 5, 4);
            $table->unsignedSmallInteger('maximum_evidence_age_days');
            $table->json('exclusions');
            $table->json('authority_envelope');
            $table->json('forecast_assumptions');
            $table->json('forecast_projection');
            $table->string('schema_version', 40)->default('objective_v1');
            $table->char('content_hash', 64);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'objective_id'], 'objective_versions_workspace_objective_fk')
                ->references(['workspace_id', 'id'])->on('objectives')->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'conversion_definition_id', 'conversion_definition_version_id'],
                'objective_versions_conversion_fk',
            )->references(['workspace_id', 'conversion_definition_id', 'id'])
                ->on('conversion_definition_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'segment_version_id'], 'objective_versions_segment_fk')
                ->references(['workspace_id', 'id'])->on('segment_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'audience_snapshot_id'], 'objective_versions_snapshot_fk')
                ->references(['workspace_id', 'id'])->on('audience_snapshots')->restrictOnDelete();
            $table->foreign(['workspace_id', 'offer_id', 'offer_version_id'], 'objective_versions_offer_fk')
                ->references(['workspace_id', 'offer_id', 'id'])->on('offer_versions')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'objective_versions_workspace_id_unique');
            $table->unique(['workspace_id', 'objective_id', 'version'], 'objective_versions_number_unique');
            $table->unique(['workspace_id', 'objective_id', 'content_hash'], 'objective_versions_content_unique');
        });

        Schema::table('objectives', function (Blueprint $table): void {
            $table->foreign(['workspace_id', 'current_version_id'], 'objectives_current_version_fk')
                ->references(['workspace_id', 'id'])->on('objective_versions')->restrictOnDelete();
        });

        Schema::create('objective_state_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('objective_id');
            $table->uuid('objective_version_id')->nullable();
            $table->string('from_state', 24)->nullable();
            $table->string('to_state', 24);
            $table->string('reason_code', 80)->nullable();
            $table->json('context');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'objective_id'], 'objective_events_workspace_objective_fk')
                ->references(['workspace_id', 'id'])->on('objectives')->restrictOnDelete();
            $table->foreign(['workspace_id', 'objective_version_id'], 'objective_events_workspace_version_fk')
                ->references(['workspace_id', 'id'])->on('objective_versions')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'objective_events_workspace_id_unique');
            $table->index(['workspace_id', 'objective_id', 'created_at'], 'objective_events_timeline_index');
        });

        Schema::create('strategies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('current_version_id')->nullable();
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'strategies_workspace_id_unique');
            $table->index(['workspace_id', 'status', 'created_at'], 'strategies_workspace_status_index');
        });

        Schema::create('strategy_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('strategy_id');
            $table->unsignedInteger('version');
            $table->uuid('objective_version_id');
            $table->uuid('audience_snapshot_id');
            $table->uuid('offer_version_id');
            $table->string('name', 160);
            $table->text('positioning');
            $table->text('problem_frame');
            $table->text('proof_summary');
            $table->string('cta_kind', 48);
            $table->string('cta_text', 500);
            $table->json('proof_claim_ids');
            $table->json('personalisation_contract');
            $table->json('message_generation_contract');
            $table->json('stop_conditions');
            $table->string('channel', 16)->default('email');
            $table->string('sequence_contract', 40)->default('one_new_thread_v1');
            $table->string('schema_version', 40)->default('strategy_v1');
            $table->char('content_hash', 64);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'strategy_id'], 'strategy_versions_workspace_strategy_fk')
                ->references(['workspace_id', 'id'])->on('strategies')->restrictOnDelete();
            $table->foreign(['workspace_id', 'objective_version_id'], 'strategy_versions_objective_fk')
                ->references(['workspace_id', 'id'])->on('objective_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'audience_snapshot_id'], 'strategy_versions_snapshot_fk')
                ->references(['workspace_id', 'id'])->on('audience_snapshots')->restrictOnDelete();
            $table->foreign(['workspace_id', 'offer_version_id'], 'strategy_versions_offer_fk')
                ->references(['workspace_id', 'id'])->on('offer_versions')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'strategy_versions_workspace_id_unique');
            $table->unique(['workspace_id', 'strategy_id', 'version'], 'strategy_versions_number_unique');
            $table->unique(['workspace_id', 'strategy_id', 'content_hash'], 'strategy_versions_content_unique');
        });

        Schema::table('strategies', function (Blueprint $table): void {
            $table->foreign(['workspace_id', 'current_version_id'], 'strategies_current_version_fk')
                ->references(['workspace_id', 'id'])->on('strategy_versions')->restrictOnDelete();
        });

        Schema::create('gtm_experiments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('objective_id');
            $table->uuid('objective_version_id');
            $table->uuid('audience_snapshot_id');
            $table->uuid('conversion_definition_id');
            $table->uuid('conversion_definition_version_id');
            $table->string('name', 160);
            $table->text('hypothesis')->nullable();
            $table->string('status', 24)->default('draft');
            $table->string('assignment_unit', 16)->default('account');
            $table->text('assignment_seed')->nullable();
            $table->char('assignment_seed_hash', 64)->nullable();
            $table->string('assignment_algorithm_version', 48)->default('hmac_sha256_basis_points_v1');
            $table->unsignedInteger('allocation_version')->default(1);
            $table->json('guardrails');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'objective_id'], 'gtm_experiments_objective_fk')
                ->references(['workspace_id', 'id'])->on('objectives')->restrictOnDelete();
            $table->foreign(['workspace_id', 'objective_version_id'], 'gtm_experiments_objective_version_fk')
                ->references(['workspace_id', 'id'])->on('objective_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'audience_snapshot_id'], 'gtm_experiments_snapshot_fk')
                ->references(['workspace_id', 'id'])->on('audience_snapshots')->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'conversion_definition_id', 'conversion_definition_version_id'],
                'gtm_experiments_conversion_fk',
            )->references(['workspace_id', 'conversion_definition_id', 'id'])
                ->on('conversion_definition_versions')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'gtm_experiments_workspace_id_unique');
            $table->index(['workspace_id', 'objective_id', 'status'], 'gtm_experiments_objective_status_index');
        });

        Schema::create('gtm_experiment_arms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('gtm_experiment_id');
            $table->uuid('strategy_version_id');
            $table->uuid('offer_version_id');
            $table->string('key', 16);
            $table->string('label', 100);
            $table->unsignedSmallInteger('allocation_basis_points');
            $table->json('declared_differences');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'gtm_experiment_id'], 'gtm_arms_workspace_experiment_fk')
                ->references(['workspace_id', 'id'])->on('gtm_experiments')->restrictOnDelete();
            $table->foreign(['workspace_id', 'strategy_version_id'], 'gtm_arms_workspace_strategy_fk')
                ->references(['workspace_id', 'id'])->on('strategy_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'offer_version_id'], 'gtm_arms_workspace_offer_fk')
                ->references(['workspace_id', 'id'])->on('offer_versions')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'gtm_arms_workspace_id_unique');
            $table->unique(['workspace_id', 'gtm_experiment_id', 'key'], 'gtm_arms_experiment_key_unique');
        });

        Schema::create('gtm_experiment_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('gtm_experiment_id');
            $table->uuid('gtm_experiment_arm_id');
            $table->uuid('audience_snapshot_id');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->char('assignment_unit_key_hash', 64);
            $table->unsignedSmallInteger('bucket');
            $table->unsignedInteger('allocation_version');
            $table->string('algorithm_version', 48);
            $table->char('eligibility_snapshot_hash', 64);
            $table->timestamp('assigned_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'gtm_experiment_id'], 'gtm_assignments_experiment_fk')
                ->references(['workspace_id', 'id'])->on('gtm_experiments')->restrictOnDelete();
            $table->foreign(['workspace_id', 'gtm_experiment_arm_id'], 'gtm_assignments_arm_fk')
                ->references(['workspace_id', 'id'])->on('gtm_experiment_arms')->restrictOnDelete();
            $table->foreign(['workspace_id', 'audience_snapshot_id'], 'gtm_assignments_snapshot_fk')
                ->references(['workspace_id', 'id'])->on('audience_snapshots')->restrictOnDelete();
            $table->foreign(['workspace_id', 'account_id'], 'gtm_assignments_account_fk')
                ->references(['workspace_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'gtm_assignments_workspace_id_unique');
            $table->unique(['workspace_id', 'gtm_experiment_id', 'account_id'], 'gtm_assignments_account_unique');
            $table->index(['workspace_id', 'gtm_experiment_arm_id'], 'gtm_assignments_arm_index');
        });

        Schema::create('gtm_experiment_assignment_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('gtm_experiment_assignment_id');
            $table->uuid('audience_snapshot_member_id');
            $table->foreignId('person_id')->nullable();
            $table->char('member_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'gtm_experiment_assignment_id'], 'gtm_assignment_members_assignment_fk')
                ->references(['workspace_id', 'id'])->on('gtm_experiment_assignments')->restrictOnDelete();
            $table->foreign(['workspace_id', 'audience_snapshot_member_id'], 'gtm_assignment_members_snapshot_member_fk')
                ->references(['workspace_id', 'id'])->on('audience_snapshot_members')->restrictOnDelete();
            $table->foreign(['workspace_id', 'person_id'], 'gtm_assignment_members_person_fk')
                ->references(['workspace_id', 'id'])->on('people')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'gtm_assignment_members_workspace_id_unique');
            $table->unique(
                ['workspace_id', 'gtm_experiment_assignment_id', 'audience_snapshot_member_id'],
                'gtm_assignment_members_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gtm_experiment_assignment_members');
        Schema::dropIfExists('gtm_experiment_assignments');
        Schema::dropIfExists('gtm_experiment_arms');
        Schema::dropIfExists('gtm_experiments');

        Schema::table('strategies', function (Blueprint $table): void {
            $table->dropForeign('strategies_current_version_fk');
        });
        Schema::dropIfExists('strategy_versions');
        Schema::dropIfExists('strategies');
        Schema::dropIfExists('objective_state_events');

        Schema::table('objectives', function (Blueprint $table): void {
            $table->dropForeign('objectives_current_version_fk');
        });
        Schema::dropIfExists('objective_versions');

        Schema::table('audience_snapshots', function (Blueprint $table): void {
            $table->dropForeign('snapshots_workspace_objective_fk');
            $table->dropUnique('snapshots_objective_freeze_unique');
            $table->unique(
                ['workspace_id', 'segment_version_id', 'evaluation_hash'],
                'audience_snapshots_freeze_unique',
            );
        });
        Schema::table('audience_snapshots', function (Blueprint $table): void {
            $table->dropIndex('snapshots_workspace_segment_index');
        });

        Schema::dropIfExists('objectives');
    }
};
