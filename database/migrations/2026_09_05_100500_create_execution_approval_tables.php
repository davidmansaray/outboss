<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('outreach_policy_version_id');
            $table->uuid('execution_plan_id');
            $table->uuid('execution_plan_action_id');
            $table->string('stage', 24);
            $table->string('result', 24);
            $table->char('input_hash', 64);
            $table->char('dependency_hash', 64);
            $table->json('reason_codes');
            $table->json('safe_details');
            $table->string('evaluator_version', 48);
            $table->timestamp('evaluated_at');
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('created_at');

            $table->unique(['workspace_id', 'id'], 'policy_evaluations_workspace_id_unique');
            $table->foreign(
                ['workspace_id', 'outreach_policy_version_id'],
                'policy_evaluations_workspace_policy_fk',
            )->references(['workspace_id', 'id'])->on('outreach_policy_versions')->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'execution_plan_id'],
                'policy_evaluations_workspace_plan_fk',
            )->references(['workspace_id', 'id'])->on('execution_plans')->cascadeOnDelete();
            $table->foreign(
                ['workspace_id', 'execution_plan_action_id'],
                'policy_evaluations_workspace_action_fk',
            )->references(['workspace_id', 'id'])->on('execution_plan_actions')->cascadeOnDelete();
            $table->index(
                ['workspace_id', 'execution_plan_action_id', 'stage', 'evaluated_at'],
                'policy_evaluations_action_stage_index',
            );
        });

        Schema::create('approval_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('execution_plan_id');
            $table->char('plan_hash', 64);
            $table->string('state', 24)->default('pending');
            $table->unsignedInteger('action_count');
            $table->char('display_manifest_hash', 64);
            $table->char('decision_input_hash', 64);
            $table->foreignId('decision_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('reason_code', 80)->nullable();
            $table->string('reason_detail', 500)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'approval_batches_workspace_id_unique');
            $table->foreign(
                ['workspace_id', 'execution_plan_id'],
                'approval_batches_workspace_plan_fk',
            )->references(['workspace_id', 'id'])->on('execution_plans')->cascadeOnDelete();
            $table->unique(['workspace_id', 'execution_plan_id'], 'approval_batches_plan_unique');
            $table->index(['workspace_id', 'state', 'expires_at'], 'approval_batches_state_expiry_index');
        });

        Schema::create('approval_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('approval_batch_id');
            $table->uuid('execution_plan_id');
            $table->uuid('execution_plan_action_id');
            $table->uuid('policy_evaluation_id');
            $table->char('plan_hash', 64);
            $table->char('action_fingerprint', 64);
            $table->uuid('outreach_policy_version_id');
            $table->char('policy_input_hash', 64);
            $table->string('decision', 16);
            $table->foreignId('decision_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at');

            $table->unique(['workspace_id', 'id'], 'approval_grants_workspace_id_unique');
            $table->foreign(
                ['workspace_id', 'approval_batch_id'],
                'approval_grants_workspace_batch_fk',
            )->references(['workspace_id', 'id'])->on('approval_batches')->cascadeOnDelete();
            $table->foreign(
                ['workspace_id', 'execution_plan_id'],
                'approval_grants_workspace_plan_fk',
            )->references(['workspace_id', 'id'])->on('execution_plans')->cascadeOnDelete();
            $table->foreign(
                ['workspace_id', 'execution_plan_action_id'],
                'approval_grants_workspace_action_fk',
            )->references(['workspace_id', 'id'])->on('execution_plan_actions')->cascadeOnDelete();
            $table->foreign(
                ['workspace_id', 'policy_evaluation_id'],
                'approval_grants_workspace_evaluation_fk',
            )->references(['workspace_id', 'id'])->on('policy_evaluations')->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'outreach_policy_version_id'],
                'approval_grants_workspace_policy_fk',
            )->references(['workspace_id', 'id'])->on('outreach_policy_versions')->restrictOnDelete();
            $table->unique(
                ['workspace_id', 'execution_plan_action_id'],
                'approval_grants_exact_action_unique',
            );
            $table->unique(
                ['workspace_id', 'approval_batch_id', 'execution_plan_action_id'],
                'approval_grants_batch_action_unique',
            );
        });

        Schema::create('approval_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('approval_batch_id');
            $table->uuid('approval_grant_id');
            $table->uuid('execution_plan_action_id');
            $table->string('event', 24);
            $table->string('prior_state', 24)->nullable();
            $table->string('projected_state', 24);
            $table->string('reason_code', 80);
            $table->char('dependency_hash', 64);
            $table->char('event_input_hash', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->foreign(
                ['workspace_id', 'approval_batch_id'],
                'approval_events_workspace_batch_fk',
            )->references(['workspace_id', 'id'])->on('approval_batches')->cascadeOnDelete();
            $table->foreign(
                ['workspace_id', 'approval_grant_id'],
                'approval_events_workspace_grant_fk',
            )->references(['workspace_id', 'id'])->on('approval_grants')->cascadeOnDelete();
            $table->foreign(
                ['workspace_id', 'execution_plan_action_id'],
                'approval_events_workspace_action_fk',
            )->references(['workspace_id', 'id'])->on('execution_plan_actions')->cascadeOnDelete();
            $table->unique(
                ['workspace_id', 'approval_grant_id', 'event'],
                'approval_events_grant_event_unique',
            );
            $table->index(
                ['workspace_id', 'approval_batch_id', 'occurred_at'],
                'approval_events_batch_time_index',
            );
        });

        Schema::create('execution_action_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('execution_plan_action_id');
            $table->foreignId('campaign_recipient_id')->nullable()->constrained('campaign_recipients')->restrictOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->restrictOnDelete();
            $table->foreignId('send_attempt_id')->nullable()->constrained('send_attempts')->restrictOnDelete();
            $table->string('event', 32);
            $table->string('provider_request_id', 191)->nullable();
            $table->string('provider_generation_id', 191)->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->char('attempt_fingerprint', 64)->nullable();
            $table->char('source_event_fingerprint', 64);
            $table->string('safe_reason_code', 80)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at');
            $table->timestamp('created_at');

            $table->foreign(
                ['workspace_id', 'execution_plan_action_id'],
                'execution_action_events_workspace_action_fk',
            )->references(['workspace_id', 'id'])->on('execution_plan_actions')->cascadeOnDelete();
            $table->unique(
                ['workspace_id', 'source_event_fingerprint'],
                'execution_action_events_source_unique',
            );
            $table->unique(
                ['workspace_id', 'execution_plan_action_id', 'attempt_fingerprint'],
                'execution_action_events_attempt_unique',
            );
            $table->index(
                ['workspace_id', 'execution_plan_action_id', 'occurred_at'],
                'execution_action_events_action_time_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_action_events');
        Schema::dropIfExists('approval_events');
        Schema::dropIfExists('approval_grants');
        Schema::dropIfExists('approval_batches');
        Schema::dropIfExists('policy_evaluations');
    }
};
