<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addReferencedWorkspaceKeys();

        Schema::create('objective_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('objective_id');
            $table->uuid('objective_version_id');
            $table->uuid('audience_snapshot_id');
            $table->uuid('gtm_experiment_id');
            $table->unsignedInteger('run_number');
            $table->string('state', 24)->default('planned');
            $table->string('current_phase', 32)->default('validate_inputs');
            $table->char('input_hash', 64);
            $table->char('idempotency_key_hash', 64);
            $table->string('schema_version', 40)->default('objective_run_v1');
            $table->string('message_validator_version', 40);
            $table->unsignedBigInteger('maximum_cost_micros');
            $table->unsignedBigInteger('reserved_cost_micros')->default(0);
            $table->unsignedBigInteger('actual_cost_micros')->default(0);
            $table->uuid('ai_budget_envelope_id')->nullable();
            $table->string('lease_owner', 160)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedBigInteger('fencing_token')->default(0);
            $table->timestamp('heartbeat_at')->nullable();
            $table->string('cancellation_reason_code', 80)->nullable();
            $table->string('cancellation_detail', 500)->nullable();
            $table->uuid('superseded_by_run_id')->nullable();
            $table->string('safe_error_code', 80)->nullable();
            $table->string('safe_error_detail', 500)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            // MySQL validates the composite self-reference while creating
            // the table, so its parent key must be declared first.
            $table->unique(['workspace_id', 'id'], 'objective_runs_workspace_id_unique');
            $table->foreign(['workspace_id', 'objective_id'], 'objective_runs_workspace_objective_fk')
                ->references(['workspace_id', 'id'])->on('objectives')->restrictOnDelete();
            $table->foreign(['workspace_id', 'objective_version_id'], 'objective_runs_workspace_version_fk')
                ->references(['workspace_id', 'id'])->on('objective_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'audience_snapshot_id'], 'objective_runs_workspace_snapshot_fk')
                ->references(['workspace_id', 'id'])->on('audience_snapshots')->restrictOnDelete();
            $table->foreign(['workspace_id', 'gtm_experiment_id'], 'objective_runs_workspace_experiment_fk')
                ->references(['workspace_id', 'id'])->on('gtm_experiments')->restrictOnDelete();
            $table->foreign(['workspace_id', 'ai_budget_envelope_id'], 'objective_runs_workspace_budget_fk')
                ->references(['workspace_id', 'id'])->on('ai_budget_envelopes')->restrictOnDelete();
            $table->foreign(['workspace_id', 'superseded_by_run_id'], 'objective_runs_workspace_successor_fk')
                ->references(['workspace_id', 'id'])->on('objective_runs')->restrictOnDelete();
            $table->unique(['workspace_id', 'objective_id', 'run_number'], 'objective_runs_number_unique');
            $table->unique(['workspace_id', 'idempotency_key_hash'], 'objective_runs_idempotency_unique');
            $table->index(['workspace_id', 'objective_id', 'state'], 'objective_runs_objective_state_index');
            $table->index(['workspace_id', 'state', 'lease_expires_at'], 'objective_runs_lease_index');
            $table->index(['workspace_id', 'created_at'], 'objective_runs_workspace_created_index');
        });

        Schema::create('objective_run_phases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('objective_run_id');
            $table->string('phase', 32);
            $table->unsignedInteger('attempt_number');
            $table->string('state', 24)->default('queued');
            $table->char('input_hash', 64);
            $table->char('output_hash', 64)->nullable();
            $table->string('safe_error_code', 80)->nullable();
            $table->string('safe_error_detail', 500)->nullable();
            $table->string('lease_owner', 160)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedBigInteger('fencing_token')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'objective_run_id'], 'run_phases_workspace_run_fk')
                ->references(['workspace_id', 'id'])->on('objective_runs')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'run_phases_workspace_id_unique');
            $table->unique(
                ['workspace_id', 'objective_run_id', 'phase', 'attempt_number'],
                'run_phases_run_phase_attempt_unique',
            );
            $table->index(['workspace_id', 'objective_run_id', 'state'], 'run_phases_run_state_index');
            $table->index(['workspace_id', 'created_at'], 'run_phases_workspace_created_index');
        });

        Schema::create('objective_run_dispatches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('objective_run_id');
            $table->string('phase', 32);
            $table->char('input_hash', 64);
            $table->string('state', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->string('safe_error_code', 80)->nullable();
            $table->string('safe_error_detail', 500)->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'objective_run_id'], 'run_dispatches_workspace_run_fk')
                ->references(['workspace_id', 'id'])->on('objective_runs')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'run_dispatches_workspace_id_unique');
            $table->unique(
                ['workspace_id', 'objective_run_id', 'phase', 'input_hash'],
                'run_dispatches_run_phase_input_unique',
            );
            $table->index(['workspace_id', 'state', 'available_at'], 'run_dispatches_available_index');
            $table->index(['workspace_id', 'created_at'], 'run_dispatches_workspace_created_index');
        });

        Schema::create('objective_run_arm_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('objective_run_id');
            $table->uuid('gtm_experiment_arm_id');
            $table->uuid('ai_budget_run_id');
            $table->uuid('ai_provider_operation_id')->nullable();
            $table->string('state', 24)->default('planned');
            $table->string('prompt_version', 40);
            $table->string('schema_version', 40);
            $table->string('validator_version', 40);
            $table->string('model_id', 160);
            $table->unsignedInteger('task_policy_version');
            $table->char('input_hash', 64);
            $table->text('subject_template')->nullable();
            $table->longText('body_template')->nullable();
            $table->json('claim_manifest')->nullable();
            $table->json('cta_manifest')->nullable();
            $table->char('output_hash', 64)->nullable();
            $table->char('template_hash', 64)->nullable();
            $table->string('safe_error_code', 80)->nullable();
            $table->string('safe_error_detail', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'objective_run_id'], 'arm_drafts_workspace_run_fk')
                ->references(['workspace_id', 'id'])->on('objective_runs')->restrictOnDelete();
            $table->foreign(['workspace_id', 'gtm_experiment_arm_id'], 'arm_drafts_workspace_arm_fk')
                ->references(['workspace_id', 'id'])->on('gtm_experiment_arms')->restrictOnDelete();
            $table->foreign(['workspace_id', 'ai_budget_run_id'], 'arm_drafts_workspace_budget_run_fk')
                ->references(['workspace_id', 'id'])->on('ai_budget_runs')->restrictOnDelete();
            $table->foreign(['workspace_id', 'ai_provider_operation_id'], 'arm_drafts_workspace_operation_fk')
                ->references(['workspace_id', 'id'])->on('ai_provider_operations')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'arm_drafts_workspace_id_unique');
            $table->unique(
                ['workspace_id', 'objective_run_id', 'gtm_experiment_arm_id'],
                'arm_drafts_run_arm_unique',
            );
            $table->index(['workspace_id', 'objective_run_id', 'state'], 'arm_drafts_run_state_index');
            $table->index(['workspace_id', 'created_at'], 'arm_drafts_workspace_created_index');
        });

        Schema::create('execution_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('objective_run_id');
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('draft');
            $table->uuid('objective_version_id');
            $table->uuid('audience_snapshot_id');
            $table->uuid('gtm_experiment_id');
            $table->uuid('conversion_definition_version_id');
            $table->uuid('offer_version_id');
            $table->uuid('outreach_policy_version_id');
            $table->string('model_id', 160);
            $table->string('ai_task_version', 40);
            $table->string('prompt_version', 40);
            $table->string('schema_version', 40);
            $table->string('validator_version', 40);
            $table->unsignedInteger('arm_count');
            $table->unsignedInteger('account_count');
            $table->unsignedInteger('people_count');
            $table->unsignedInteger('action_count');
            $table->unsignedBigInteger('maximum_cost_micros');
            $table->unsignedBigInteger('estimated_cost_micros');
            $table->unsignedBigInteger('actual_cost_micros')->default(0);
            $table->json('input_manifest');
            $table->char('evidence_manifest_hash', 64);
            $table->json('compilation_manifest');
            $table->char('canonical_payload_hash', 64);
            $table->char('plan_hash', 64);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('compiled_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'objective_run_id'], 'execution_plans_workspace_run_fk')
                ->references(['workspace_id', 'id'])->on('objective_runs')->restrictOnDelete();
            $table->foreign(['workspace_id', 'objective_version_id'], 'execution_plans_workspace_objective_version_fk')
                ->references(['workspace_id', 'id'])->on('objective_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'audience_snapshot_id'], 'execution_plans_workspace_snapshot_fk')
                ->references(['workspace_id', 'id'])->on('audience_snapshots')->restrictOnDelete();
            $table->foreign(['workspace_id', 'gtm_experiment_id'], 'execution_plans_workspace_experiment_fk')
                ->references(['workspace_id', 'id'])->on('gtm_experiments')->restrictOnDelete();
            $table->foreign(['workspace_id', 'conversion_definition_version_id'], 'execution_plans_workspace_conversion_fk')
                ->references(['workspace_id', 'id'])->on('conversion_definition_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'offer_version_id'], 'execution_plans_workspace_offer_fk')
                ->references(['workspace_id', 'id'])->on('offer_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'outreach_policy_version_id'], 'execution_plans_workspace_policy_fk')
                ->references(['workspace_id', 'id'])->on('outreach_policy_versions')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'execution_plans_workspace_id_unique');
            $table->unique(['workspace_id', 'objective_run_id', 'version'], 'execution_plans_run_version_unique');
            $table->unique(['workspace_id', 'objective_run_id', 'plan_hash'], 'execution_plans_run_hash_unique');
            $table->index(['workspace_id', 'objective_run_id', 'status'], 'execution_plans_run_status_index');
            $table->index(['workspace_id', 'created_at'], 'execution_plans_workspace_created_index');
        });

        Schema::table('objectives', function (Blueprint $table): void {
            $table->foreign(['workspace_id', 'current_plan_id'], 'objectives_workspace_current_plan_fk')
                ->references(['workspace_id', 'id'])->on('execution_plans')->restrictOnDelete();
        });

        Schema::create('execution_plan_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('execution_plan_id');
            $table->uuid('gtm_experiment_assignment_id');
            $table->uuid('gtm_experiment_arm_id');
            $table->uuid('objective_run_arm_draft_id');
            $table->uuid('strategy_version_id');
            $table->uuid('audience_snapshot_member_id');
            $table->foreignId('account_id')->nullable();
            $table->foreignId('person_id')->nullable();
            $table->uuid('offer_version_id');
            $table->uuid('conversion_definition_version_id');
            $table->json('selected_claim_ids');
            $table->json('selected_evidence_ids');
            $table->json('selected_claim_safety_hashes');
            $table->char('offer_content_hash', 64);
            $table->char('offer_contract_hash', 64);
            $table->text('cta_destination_url');
            $table->string('cta_response_route', 160);
            $table->string('action_kind', 32)->default('email_new_thread');
            $table->unsignedSmallInteger('position')->default(1);
            $table->char('destination_email_hash', 64);
            $table->text('destination_email_address')->nullable();
            $table->text('subject')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->char('template_hash', 64);
            $table->char('recipient_snapshot_hash', 64);
            $table->char('content_hash', 64);
            $table->foreignId('mailbox_id');
            $table->foreignId('provider_connection_id');
            $table->unsignedInteger('provider_connection_version')->nullable();
            $table->text('sender_address_snapshot');
            $table->text('sender_display_name_snapshot')->nullable();
            $table->text('reply_to_snapshot')->nullable();
            $table->timestamp('scheduled_not_before');
            $table->timestamp('scheduled_not_after');
            $table->char('policy_input_hash', 64);
            $table->char('action_fingerprint', 64);
            $table->string('state', 24)->default('planned');
            $table->timestamps();

            $table->foreign(['workspace_id', 'execution_plan_id'], 'plan_actions_workspace_plan_fk')
                ->references(['workspace_id', 'id'])->on('execution_plans')->restrictOnDelete();
            $table->foreign(['workspace_id', 'gtm_experiment_assignment_id'], 'plan_actions_workspace_assignment_fk')
                ->references(['workspace_id', 'id'])->on('gtm_experiment_assignments')->restrictOnDelete();
            $table->foreign(['workspace_id', 'gtm_experiment_arm_id'], 'plan_actions_workspace_arm_fk')
                ->references(['workspace_id', 'id'])->on('gtm_experiment_arms')->restrictOnDelete();
            $table->foreign(['workspace_id', 'objective_run_arm_draft_id'], 'plan_actions_workspace_arm_draft_fk')
                ->references(['workspace_id', 'id'])->on('objective_run_arm_drafts')->restrictOnDelete();
            $table->foreign(['workspace_id', 'strategy_version_id'], 'plan_actions_workspace_strategy_fk')
                ->references(['workspace_id', 'id'])->on('strategy_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'audience_snapshot_member_id'], 'plan_actions_workspace_member_fk')
                ->references(['workspace_id', 'id'])->on('audience_snapshot_members')->restrictOnDelete();
            $table->foreign(['workspace_id', 'account_id'], 'plan_actions_workspace_account_fk')
                ->references(['workspace_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['workspace_id', 'person_id'], 'plan_actions_workspace_person_fk')
                ->references(['workspace_id', 'id'])->on('people')->restrictOnDelete();
            $table->foreign(['workspace_id', 'offer_version_id'], 'plan_actions_workspace_offer_fk')
                ->references(['workspace_id', 'id'])->on('offer_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'conversion_definition_version_id'], 'plan_actions_workspace_conversion_fk')
                ->references(['workspace_id', 'id'])->on('conversion_definition_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'mailbox_id'], 'plan_actions_workspace_mailbox_fk')
                ->references(['workspace_id', 'id'])->on('mailboxes')->restrictOnDelete();
            $table->foreign(['workspace_id', 'provider_connection_id'], 'plan_actions_workspace_connection_fk')
                ->references(['workspace_id', 'id'])->on('provider_connections')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'plan_actions_workspace_id_unique');
            $table->unique(
                ['workspace_id', 'execution_plan_id', 'audience_snapshot_member_id'],
                'plan_actions_plan_member_unique',
            );
            $table->unique(
                ['workspace_id', 'execution_plan_id', 'action_fingerprint'],
                'plan_actions_plan_fingerprint_unique',
            );
            $table->index(['workspace_id', 'execution_plan_id', 'state'], 'plan_actions_plan_state_index');
            $table->index(['workspace_id', 'mailbox_id', 'scheduled_not_before'], 'plan_actions_mailbox_schedule_index');
            $table->index(['workspace_id', 'created_at'], 'plan_actions_workspace_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('objectives', function (Blueprint $table): void {
            $table->dropForeign('objectives_workspace_current_plan_fk');
        });
        Schema::dropIfExists('execution_plan_actions');
        Schema::dropIfExists('execution_plans');
        Schema::dropIfExists('objective_run_arm_drafts');
        Schema::dropIfExists('objective_run_dispatches');
        Schema::dropIfExists('objective_run_phases');
        Schema::dropIfExists('objective_runs');

        $this->dropReferencedWorkspaceKeys();
    }

    private function addReferencedWorkspaceKeys(): void
    {
        if (! Schema::hasIndex('conversion_definition_versions', 'conversion_definition_versions_workspace_id_unique')
            && ! Schema::hasIndex('conversion_definition_versions', 'odg_s3_conversion_versions_workspace_id_unique')) {
            Schema::table('conversion_definition_versions', function (Blueprint $table): void {
                $table->unique(
                    ['workspace_id', 'id'],
                    'odg_s3_conversion_versions_workspace_id_unique',
                );
            });
        }

        if (! Schema::hasIndex('mailboxes', 'mailboxes_workspace_id_unique')
            && ! Schema::hasIndex('mailboxes', 'odg_s3_mailboxes_workspace_id_unique')) {
            Schema::table('mailboxes', function (Blueprint $table): void {
                $table->unique(['workspace_id', 'id'], 'odg_s3_mailboxes_workspace_id_unique');
            });
        }

        if (! Schema::hasIndex('provider_connections', 'provider_connections_workspace_id_unique')
            && ! Schema::hasIndex('provider_connections', 'odg_s3_provider_connections_ws_id_unique')) {
            Schema::table('provider_connections', function (Blueprint $table): void {
                $table->unique(['workspace_id', 'id'], 'odg_s3_provider_connections_ws_id_unique');
            });
        }

        if (! Schema::hasIndex('ai_provider_operations', 'ai_provider_operations_workspace_id_unique')
            && ! Schema::hasIndex('ai_provider_operations', 'odg_s3_ai_provider_ops_ws_id_unique')) {
            Schema::table('ai_provider_operations', function (Blueprint $table): void {
                $table->unique(['workspace_id', 'id'], 'odg_s3_ai_provider_ops_ws_id_unique');
            });
        }

        if (! Schema::hasIndex('ai_budget_runs', 'ai_budget_runs_workspace_id_unique')
            && ! Schema::hasIndex('ai_budget_runs', 'odg_s3_ai_budget_runs_ws_id_unique')) {
            Schema::table('ai_budget_runs', function (Blueprint $table): void {
                $table->unique(['workspace_id', 'id'], 'odg_s3_ai_budget_runs_ws_id_unique');
            });
        }
    }

    private function dropReferencedWorkspaceKeys(): void
    {
        if (Schema::hasIndex('ai_budget_runs', 'odg_s3_ai_budget_runs_ws_id_unique')) {
            Schema::table('ai_budget_runs', function (Blueprint $table): void {
                $table->dropUnique('odg_s3_ai_budget_runs_ws_id_unique');
            });
        }

        if (Schema::hasIndex('ai_provider_operations', 'odg_s3_ai_provider_ops_ws_id_unique')) {
            Schema::table('ai_provider_operations', function (Blueprint $table): void {
                $table->dropUnique('odg_s3_ai_provider_ops_ws_id_unique');
            });
        }

        if (Schema::hasIndex('provider_connections', 'odg_s3_provider_connections_ws_id_unique')) {
            Schema::table('provider_connections', function (Blueprint $table): void {
                $table->dropUnique('odg_s3_provider_connections_ws_id_unique');
            });
        }

        if (Schema::hasIndex('mailboxes', 'odg_s3_mailboxes_workspace_id_unique')) {
            Schema::table('mailboxes', function (Blueprint $table): void {
                $table->dropUnique('odg_s3_mailboxes_workspace_id_unique');
            });
        }

        if (Schema::hasIndex('conversion_definition_versions', 'odg_s3_conversion_versions_workspace_id_unique')) {
            Schema::table('conversion_definition_versions', function (Blueprint $table): void {
                $table->dropUnique('odg_s3_conversion_versions_workspace_id_unique');
            });
        }
    }
};
