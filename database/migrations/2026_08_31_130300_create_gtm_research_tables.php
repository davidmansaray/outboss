<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gtm_profile_research_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('gtm_profile_id')->constrained('gtm_profiles')->restrictOnDelete();
            $table->string('state', 32)->default('queued');
            $table->string('awaiting_reason', 24)->nullable();
            $table->uuid('budget_run_id')->nullable();
            $table->uuid('parent_run_id')->nullable();
            $table->string('lineage_type', 24)->nullable();
            $table->string('website_url_snapshot', 2048)->nullable();
            $table->uuid('base_profile_version_id')->nullable();
            $table->char('input_hash', 64);
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('safe_failure_code', 64)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('budget_run_id', 'gtm_research_runs_budget_run_fk')
                ->references('id')
                ->on('ai_budget_runs')
                ->restrictOnDelete();
            $table->foreign('parent_run_id', 'gtm_research_runs_parent_fk')
                ->references('id')
                ->on('gtm_profile_research_runs')
                ->restrictOnDelete();
            $table->unique(['gtm_profile_id', 'id'], 'gtm_research_runs_profile_id_unique');
            $table->index(['gtm_profile_id', 'state'], 'gtm_research_runs_profile_state_index');
            $table->index(['gtm_profile_id', 'created_at'], 'gtm_research_runs_profile_created_index');
        });

        Schema::create('gtm_profile_research_phases', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('research_run_id')->constrained('gtm_profile_research_runs')->restrictOnDelete();
            $table->string('phase', 48);
            $table->string('status', 24)->default('pending');
            $table->json('successful_checkpoint')->nullable();
            $table->char('checkpoint_hash', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['research_run_id', 'phase'], 'gtm_research_phases_run_phase_unique');
            $table->index(['research_run_id', 'status'], 'gtm_research_phases_run_status_index');
        });

        Schema::create('gtm_profile_research_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('phase_id')->constrained('gtm_profile_research_phases')->restrictOnDelete();
            $table->foreignUuid('research_run_id')->constrained('gtm_profile_research_runs')->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('deterministic_idempotency_key', 160);
            $table->uuid('provider_operation_id')->nullable();
            $table->char('input_hash', 64);
            $table->string('status', 24)->default('planned');
            $table->uuid('lease_owner')->nullable();
            $table->unsignedBigInteger('fencing_token')->default(0);
            $table->timestamp('lease_expires_at')->nullable();
            $table->json('outcome')->nullable();
            $table->string('safe_error_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('provider_operation_id', 'gtm_research_attempts_provider_operation_fk')
                ->references('id')
                ->on('ai_provider_operations')
                ->nullOnDelete();
            $table->unique(['phase_id', 'attempt_number'], 'gtm_research_attempts_phase_attempt_unique');
            $table->unique(['phase_id', 'fencing_token'], 'gtm_research_attempts_phase_fencing_unique');
            $table->unique('deterministic_idempotency_key', 'gtm_research_attempts_idempotency_unique');
            $table->index(['research_run_id', 'status'], 'gtm_research_attempts_run_status_index');
        });

        Schema::create('gtm_profile_research_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('gtm_profile_id')->constrained('gtm_profiles')->restrictOnDelete();
            $table->foreignUuid('research_run_id')->constrained('gtm_profile_research_runs')->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->timestamp('available_at');
            $table->timestamp('published_at')->nullable();
            $table->string('lease_owner', 160)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedInteger('publish_attempts')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();

            $table->unique(
                ['gtm_profile_id', 'research_run_id'],
                'gtm_research_dispatch_profile_run_unique'
            );
            $table->index(['status', 'available_at'], 'gtm_research_dispatch_status_available_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gtm_profile_research_dispatches');
        Schema::dropIfExists('gtm_profile_research_attempts');
        Schema::dropIfExists('gtm_profile_research_phases');
        Schema::table('gtm_profile_research_runs', function (Blueprint $table): void {
            $table->dropForeign('gtm_research_runs_parent_fk');
            $table->dropForeign('gtm_research_runs_budget_run_fk');
        });
        Schema::dropIfExists('gtm_profile_research_runs');
    }
};
