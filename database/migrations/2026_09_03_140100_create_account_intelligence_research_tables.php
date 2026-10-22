<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addParentCompositeKeys();

        Schema::create('account_research_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('account_id');
            $table->uuid('gtm_profile_version_id')->nullable();
            $table->uuid('offer_version_id')->nullable();
            $table->uuid('parent_run_id')->nullable();
            $table->json('account_snapshot');
            $table->json('policy_snapshot');
            $table->json('capability_snapshot');
            $table->json('freshness_policy');
            $table->unsignedBigInteger('maximum_cost_micros');
            $table->string('currency', 3)->default('USD');
            $table->char('input_hash', 64);
            $table->string('idempotency_key', 160);
            $table->unsignedInteger('orchestration_version')->default(1);
            $table->string('state', 32)->default('planned');
            $table->string('awaiting_reason', 64)->nullable();
            $table->string('safe_failure_code', 64)->nullable();
            $table->string('current_stage', 64)->nullable();
            $table->unsignedBigInteger('estimated_cost_micros')->default(0);
            $table->unsignedBigInteger('reserved_cost_micros')->default(0);
            $table->unsignedBigInteger('actual_cost_micros')->default(0);
            $table->uuid('budget_envelope_id')->nullable();
            $table->uuid('research_budget_run_id')->nullable();
            $table->uuid('qualification_budget_run_id')->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'account_id'], 'account_research_runs_workspace_account_fk')
                ->references(['workspace_id', 'id'])
                ->on('accounts')
                ->restrictOnDelete();
            $table->foreign('gtm_profile_version_id', 'account_research_runs_gtm_version_fk')
                ->references('id')->on('gtm_profile_versions')->restrictOnDelete();
            $table->foreign('offer_version_id', 'account_research_runs_offer_version_fk')
                ->references('id')->on('offer_versions')->restrictOnDelete();
            $table->foreign('research_budget_run_id', 'account_research_runs_research_budget_fk')
                ->references('id')->on('ai_budget_runs')->restrictOnDelete();
            $table->foreign('qualification_budget_run_id', 'account_research_runs_qualification_budget_fk')
                ->references('id')->on('ai_budget_runs')->restrictOnDelete();

            $table->unique(['workspace_id', 'id'], 'account_research_runs_workspace_id_unique');
            $table->unique(['workspace_id', 'account_id', 'idempotency_key'], 'account_research_runs_idempotency_unique');
            $table->index(['workspace_id', 'account_id', 'created_at'], 'account_research_runs_account_created_index');
            $table->index(['workspace_id', 'state', 'updated_at'], 'account_research_runs_state_updated_index');
            $table->index(['state', 'updated_at'], 'account_research_runs_sweep_index');
        });

        // MySQL validates a self-referencing composite FK before secondary
        // indexes declared in the same CREATE statement are available. Add
        // this constraint after the workspace/id unique key exists.
        Schema::table('account_research_runs', function (Blueprint $table): void {
            $table->foreign(['workspace_id', 'parent_run_id'], 'account_research_runs_parent_fk')
                ->references(['workspace_id', 'id'])
                ->on('account_research_runs')
                ->restrictOnDelete();
        });

        Schema::create('account_research_run_inputs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('research_run_id');
            $table->string('input_type', 32);
            $table->uuid('source_observation_id')->nullable();
            $table->uuid('prior_claim_id')->nullable();
            $table->char('source_policy_hash', 64);
            $table->string('llm_use_classification', 32)->default('allowed');
            $table->string('display_classification', 32)->default('standard');
            $table->string('export_classification', 32)->default('standard');
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->char('content_hash', 64);
            $table->timestamps();

            $table->foreign(['workspace_id', 'research_run_id'], 'account_research_inputs_workspace_run_fk')
                ->references(['workspace_id', 'id'])->on('account_research_runs')->restrictOnDelete();
            $table->foreign(['workspace_id', 'source_observation_id'], 'account_research_inputs_workspace_observation_fk')
                ->references(['workspace_id', 'id'])->on('source_observations')->restrictOnDelete();
            // prior_claim_id is added after research_claims exists below.
            $table->unique(['workspace_id', 'research_run_id', 'input_type', 'content_hash'], 'account_research_inputs_logical_unique');
            $table->index(['workspace_id', 'source_observation_id'], 'account_research_inputs_observation_index');
            $table->index(['workspace_id', 'prior_claim_id'], 'account_research_inputs_claim_index');
        });

        Schema::create('account_research_stages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('research_run_id');
            $table->unsignedInteger('stage_order');
            $table->string('stage_key', 64);
            $table->string('state', 32)->default('pending');
            $table->json('entry_predicate_snapshot')->nullable();
            $table->json('missing_facts_snapshot')->nullable();
            $table->unsignedBigInteger('expected_cost_micros')->default(0);
            $table->unsignedBigInteger('maximum_cost_micros')->default(0);
            $table->unsignedInteger('implementation_version')->default(1);
            $table->json('checkpoint_reference')->nullable();
            $table->char('checkpoint_hash', 64)->nullable();
            $table->string('lease_owner', 160)->nullable();
            $table->unsignedBigInteger('fencing_token')->default(0);
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('safe_error_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'research_run_id'], 'account_research_stages_workspace_run_fk')
                ->references(['workspace_id', 'id'])->on('account_research_runs')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'account_research_stages_workspace_id_unique');
            $table->unique(['workspace_id', 'research_run_id', 'stage_key'], 'account_research_stages_run_stage_unique');
            $table->unique(['workspace_id', 'research_run_id', 'stage_order'], 'account_research_stages_run_order_unique');
            $table->index(['workspace_id', 'research_run_id', 'state'], 'account_research_stages_run_state_index');
            $table->index(['state', 'lease_expires_at'], 'account_research_stages_sweep_index');
        });

        Schema::create('account_research_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('research_run_id');
            $table->uuid('stage_id');
            $table->unsignedInteger('attempt_number');
            $table->string('deterministic_idempotency_key', 180);
            $table->uuid('provider_operation_id')->nullable();
            $table->char('input_hash', 64);
            $table->string('state', 32)->default('planned');
            $table->string('lease_owner', 160)->nullable();
            $table->unsignedBigInteger('fencing_token')->default(0);
            $table->timestamp('lease_expires_at')->nullable();
            $table->json('outcome')->nullable();
            $table->string('safe_error_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'research_run_id'], 'account_research_attempts_workspace_run_fk')
                ->references(['workspace_id', 'id'])->on('account_research_runs')->restrictOnDelete();
            $table->foreign(['workspace_id', 'stage_id'], 'account_research_attempts_workspace_stage_fk')
                ->references(['workspace_id', 'id'])->on('account_research_stages')->restrictOnDelete();
            $table->foreign('provider_operation_id', 'account_research_attempts_provider_operation_fk')
                ->references('id')->on('ai_provider_operations')->nullOnDelete();
            $table->unique(['workspace_id', 'id'], 'account_research_attempts_workspace_id_unique');
            $table->unique(['workspace_id', 'stage_id', 'attempt_number'], 'account_research_attempts_stage_attempt_unique');
            $table->unique(['workspace_id', 'stage_id', 'fencing_token'], 'account_research_attempts_stage_fence_unique');
            $table->unique(['workspace_id', 'deterministic_idempotency_key'], 'account_research_attempts_idempotency_unique');
            $table->index(['workspace_id', 'research_run_id', 'state'], 'account_research_attempts_run_state_index');
            $table->index(['state', 'lease_expires_at'], 'account_research_attempts_sweep_index');
        });

        Schema::create('account_research_dispatches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('research_run_id');
            $table->string('dispatch_kind', 32)->default('stage');
            $table->string('stage_key', 64)->default('run');
            $table->string('state', 24)->default('pending');
            $table->timestamp('available_at');
            $table->timestamp('published_at')->nullable();
            $table->string('publish_lease_owner', 160)->nullable();
            $table->timestamp('publish_lease_expires_at')->nullable();
            $table->unsignedInteger('publish_attempts')->default(0);
            $table->string('safe_error_code', 64)->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'research_run_id'], 'account_research_dispatches_workspace_run_fk')
                ->references(['workspace_id', 'id'])->on('account_research_runs')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'account_research_dispatches_workspace_id_unique');
            $table->unique(['workspace_id', 'research_run_id', 'dispatch_kind', 'stage_key'], 'account_research_dispatches_logical_unique');
            $table->index(['state', 'available_at'], 'account_research_dispatches_sweep_index');
            $table->index(['workspace_id', 'research_run_id', 'state'], 'account_research_dispatches_run_state_index');
        });

        Schema::create('research_source_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('account_id');
            $table->uuid('research_run_id');
            $table->uuid('stage_id')->nullable();
            $table->string('source_type', 40);
            $table->string('canonical_public_url', 2048)->nullable();
            $table->string('registrable_domain', 253)->nullable();
            $table->timestamp('retrieved_at')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->char('content_hash', 64);
            $table->longText('content')->nullable();
            $table->string('payload_reference', 500)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('byte_count')->default(0);
            $table->string('language', 16)->nullable();
            $table->string('retrieval_capability', 64)->nullable();
            $table->unsignedInteger('retrieval_version')->default(1);
            $table->uuid('provider_operation_id')->nullable();
            $table->string('provider_request_id', 180)->nullable();
            $table->unsignedInteger('provider_item_rank')->nullable();
            $table->char('retrieval_policy_hash', 64)->nullable();
            $table->string('display_classification', 32)->default('standard');
            $table->string('llm_use_classification', 32)->default('allowed');
            $table->string('export_classification', 32)->default('standard');
            $table->string('retention_classification', 32)->default('standard');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('redacted_at')->nullable();
            $table->string('state', 24)->default('available');
            $table->string('safe_failure_code', 64)->nullable();
            $table->string('result_identity', 180);
            $table->timestamps();

            $table->foreign(['workspace_id', 'account_id'], 'research_snapshots_workspace_account_fk')
                ->references(['workspace_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['workspace_id', 'research_run_id'], 'research_snapshots_workspace_run_fk')
                ->references(['workspace_id', 'id'])->on('account_research_runs')->restrictOnDelete();
            $table->foreign(['workspace_id', 'stage_id'], 'research_snapshots_workspace_stage_fk')
                ->references(['workspace_id', 'id'])->on('account_research_stages')->restrictOnDelete();
            $table->foreign('provider_operation_id', 'research_snapshots_provider_operation_fk')
                ->references('id')->on('ai_provider_operations')->nullOnDelete();
            $table->unique(['workspace_id', 'id'], 'research_snapshots_workspace_id_unique');
            $table->unique(['workspace_id', 'research_run_id', 'result_identity'], 'research_snapshots_result_identity_unique');
            $table->index(['workspace_id', 'account_id', 'state', 'observed_at'], 'research_snapshots_account_current_index');
            $table->index(['state', 'expires_at'], 'research_snapshots_retention_index');
            $table->index(['workspace_id', 'provider_operation_id'], 'research_snapshots_operation_index');
        });

        Schema::create('research_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('account_id');
            $table->uuid('research_run_id');
            $table->uuid('supersedes_claim_id')->nullable();
            $table->string('subject_type', 24)->default('account');
            $table->string('predicate', 160);
            $table->string('value_type', 24);
            $table->json('value_json')->nullable();
            $table->char('value_hash', 64);
            $table->string('status', 24)->default('supported');
            $table->decimal('confidence', 5, 4)->default(0);
            $table->string('calibration_band', 32)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('fresh_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('extractor_kind', 40);
            $table->string('extractor_version', 40);
            $table->string('prompt_version', 80)->nullable();
            $table->string('model_version', 120)->nullable();
            $table->string('policy_version', 80)->nullable();
            $table->string('catalogue_version', 80)->nullable();
            $table->boolean('safe_for_message')->default(false);
            $table->char('content_hash', 64);
            $table->timestamps();

            $table->foreign(['workspace_id', 'account_id'], 'research_claims_workspace_account_fk')
                ->references(['workspace_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['workspace_id', 'research_run_id'], 'research_claims_workspace_run_fk')
                ->references(['workspace_id', 'id'])->on('account_research_runs')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'research_claims_workspace_id_unique');
            $table->unique(['workspace_id', 'research_run_id', 'content_hash'], 'research_claims_run_content_unique');
            $table->index(['workspace_id', 'account_id', 'predicate', 'status'], 'research_claims_account_predicate_index');
            $table->index(['workspace_id', 'account_id', 'fresh_until'], 'research_claims_freshness_index');
        });

        Schema::table('research_claims', function (Blueprint $table): void {
            $table->foreign(['workspace_id', 'supersedes_claim_id'], 'research_claims_supersedes_fk')
                ->references(['workspace_id', 'id'])->on('research_claims')->restrictOnDelete();
        });

        Schema::create('research_claim_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('claim_id');
            $table->uuid('source_snapshot_id');
            $table->string('edge_type', 16);
            $table->string('locator', 500)->nullable();
            $table->char('locator_hash', 64)->default('');
            $table->char('quote_hash', 64)->nullable();
            $table->decimal('evidence_weight', 8, 4)->nullable();
            $table->string('extraction_version', 40)->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'claim_id'], 'research_claim_evidence_workspace_claim_fk')
                ->references(['workspace_id', 'id'])->on('research_claims')->restrictOnDelete();
            $table->foreign(['workspace_id', 'source_snapshot_id'], 'research_claim_evidence_workspace_snapshot_fk')
                ->references(['workspace_id', 'id'])->on('research_source_snapshots')->restrictOnDelete();
            $table->unique(['workspace_id', 'claim_id', 'source_snapshot_id', 'edge_type', 'locator_hash'], 'research_claim_evidence_edge_unique');
            $table->index(['workspace_id', 'source_snapshot_id'], 'research_claim_evidence_snapshot_index');
        });

        Schema::create('research_claim_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('account_id');
            $table->uuid('claim_id');
            $table->string('action', 24);
            $table->json('actor_snapshot');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 2000)->nullable();
            $table->uuid('successor_claim_id')->nullable();
            $table->string('prior_status', 24)->nullable();
            $table->string('projected_status', 24)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['workspace_id', 'account_id'], 'research_claim_events_workspace_account_fk')
                ->references(['workspace_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['workspace_id', 'claim_id'], 'research_claim_events_workspace_claim_fk')
                ->references(['workspace_id', 'id'])->on('research_claims')->restrictOnDelete();
            $table->foreign(['workspace_id', 'successor_claim_id'], 'research_claim_events_successor_claim_fk')
                ->references(['workspace_id', 'id'])->on('research_claims')->restrictOnDelete();
            $table->index(['workspace_id', 'account_id', 'created_at'], 'research_claim_events_account_created_index');
            $table->index(['workspace_id', 'claim_id', 'created_at'], 'research_claim_events_claim_created_index');
        });

        Schema::table('account_research_run_inputs', function (Blueprint $table): void {
            $table->foreign(['workspace_id', 'prior_claim_id'], 'account_research_inputs_workspace_claim_fk')
                ->references(['workspace_id', 'id'])->on('research_claims')->restrictOnDelete();
        });
    }

    private function addParentCompositeKeys(): void
    {
        if (! Schema::hasIndex('accounts', 'accounts_workspace_id_unique')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->unique(['workspace_id', 'id'], 'accounts_workspace_id_unique');
            });
        }

        if (! Schema::hasIndex('source_observations', 'source_observations_workspace_id_unique')) {
            Schema::table('source_observations', function (Blueprint $table): void {
                $table->unique(['workspace_id', 'id'], 'source_observations_workspace_id_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('account_research_run_inputs', function (Blueprint $table): void {
            $table->dropForeign('account_research_inputs_workspace_claim_fk');
        });
        Schema::dropIfExists('research_claim_events');
        Schema::dropIfExists('research_claim_evidence');
        Schema::dropIfExists('research_claims');
        Schema::dropIfExists('research_source_snapshots');
        Schema::dropIfExists('account_research_dispatches');
        Schema::dropIfExists('account_research_attempts');
        Schema::dropIfExists('account_research_stages');
        Schema::dropIfExists('account_research_run_inputs');
        Schema::dropIfExists('account_research_runs');

        if (Schema::hasIndex('source_observations', 'source_observations_workspace_id_unique')) {
            Schema::table('source_observations', function (Blueprint $table): void {
                $table->dropUnique('source_observations_workspace_id_unique');
            });
        }
        if (Schema::hasIndex('accounts', 'accounts_workspace_id_unique')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->dropUnique('accounts_workspace_id_unique');
            });
        }
    }
};
