<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addOutcomeOwnershipKeys();

        Schema::create('exposures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('execution_plan_action_id');
            $table->uuid('gtm_experiment_assignment_id');
            $table->uuid('gtm_experiment_arm_id');
            $table->uuid('strategy_version_id');
            $table->uuid('offer_version_id');
            $table->uuid('objective_version_id');
            $table->uuid('objective_run_id');
            $table->foreignId('message_id');
            $table->foreignId('send_attempt_id');
            $table->foreignId('campaign_recipient_id');
            $table->foreignId('thread_id');
            $table->string('boundary', 24);
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at');
            $table->uuid('source_event_id');
            $table->char('exposure_fingerprint', 64);
            $table->timestamp('created_at');

            $table->unique(['workspace_id', 'id'], 'exposures_workspace_id_unique');
            $table->foreign(['workspace_id', 'execution_plan_action_id'], 'exposures_workspace_action_fk')
                ->references(['workspace_id', 'id'])->on('execution_plan_actions')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'gtm_experiment_assignment_id'], 'exposures_workspace_assignment_fk')
                ->references(['workspace_id', 'id'])->on('gtm_experiment_assignments')->restrictOnDelete();
            $table->foreign(['workspace_id', 'gtm_experiment_arm_id'], 'exposures_workspace_arm_fk')
                ->references(['workspace_id', 'id'])->on('gtm_experiment_arms')->restrictOnDelete();
            $table->foreign(['workspace_id', 'strategy_version_id'], 'exposures_workspace_strategy_fk')
                ->references(['workspace_id', 'id'])->on('strategy_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'offer_version_id'], 'exposures_workspace_offer_fk')
                ->references(['workspace_id', 'id'])->on('offer_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'objective_version_id'], 'exposures_workspace_objective_version_fk')
                ->references(['workspace_id', 'id'])->on('objective_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'objective_run_id'], 'exposures_workspace_run_fk')
                ->references(['workspace_id', 'id'])->on('objective_runs')->restrictOnDelete();
            $table->foreign(['workspace_id', 'message_id'], 'exposures_workspace_message_fk')
                ->references(['workspace_id', 'id'])->on('messages')->restrictOnDelete();
            $table->foreign(['workspace_id', 'send_attempt_id'], 'exposures_workspace_attempt_fk')
                ->references(['workspace_id', 'id'])->on('send_attempts')->restrictOnDelete();
            $table->foreign(['workspace_id', 'campaign_recipient_id'], 'exposures_workspace_recipient_fk')
                ->references(['workspace_id', 'id'])->on('campaign_recipients')->restrictOnDelete();
            $table->foreign(['workspace_id', 'thread_id'], 'exposures_workspace_thread_fk')
                ->references(['workspace_id', 'id'])->on('threads')->restrictOnDelete();
            $table->foreign(['workspace_id', 'source_event_id'], 'exposures_workspace_source_event_fk')
                ->references(['workspace_id', 'id'])->on('execution_action_events')->restrictOnDelete();
            $table->unique(['workspace_id', 'execution_plan_action_id'], 'exposures_action_unique');
            $table->unique(['workspace_id', 'send_attempt_id'], 'exposures_attempt_unique');
            $table->unique(['workspace_id', 'exposure_fingerprint'], 'exposures_fingerprint_unique');
            $table->index(['workspace_id', 'objective_version_id', 'occurred_at'], 'exposures_objective_time_index');
            $table->index(['workspace_id', 'objective_version_id', 'campaign_recipient_id'], 'exposures_objective_recipient_index');
        });

        Schema::create('gtm_conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('thread_id');
            $table->uuid('objective_id');
            $table->uuid('objective_version_id');
            $table->uuid('objective_run_id');
            $table->uuid('execution_plan_action_id');
            $table->uuid('exposure_id');
            $table->foreignId('account_id')->nullable();
            $table->foreignId('person_id')->nullable();
            $table->uuid('offer_version_id');
            $table->foreignId('owner_user_id')->nullable();
            $table->uuid('fulfilment_request_id')->nullable();
            $table->string('state', 24)->default('open');
            $table->string('latest_classification', 32)->nullable();
            $table->decimal('classification_confidence', 5, 4)->nullable();
            $table->string('next_action', 500)->nullable();
            $table->timestamp('next_action_due_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'gtm_conversations_workspace_id_unique');
            $table->foreign(['workspace_id', 'thread_id'], 'gtm_conversations_workspace_thread_fk')
                ->references(['workspace_id', 'id'])->on('threads')->restrictOnDelete();
            $table->foreign(['workspace_id', 'objective_id'], 'gtm_conversations_workspace_objective_fk')
                ->references(['workspace_id', 'id'])->on('objectives')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'objective_version_id'], 'gtm_conversations_workspace_version_fk')
                ->references(['workspace_id', 'id'])->on('objective_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'objective_run_id'], 'gtm_conversations_workspace_run_fk')
                ->references(['workspace_id', 'id'])->on('objective_runs')->restrictOnDelete();
            $table->foreign(['workspace_id', 'execution_plan_action_id'], 'gtm_conversations_workspace_action_fk')
                ->references(['workspace_id', 'id'])->on('execution_plan_actions')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'exposure_id'], 'gtm_conversations_workspace_exposure_fk')
                ->references(['workspace_id', 'id'])->on('exposures')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'account_id'], 'gtm_conversations_workspace_account_fk')
                ->references(['workspace_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['workspace_id', 'person_id'], 'gtm_conversations_workspace_person_fk')
                ->references(['workspace_id', 'id'])->on('people')->restrictOnDelete();
            $table->foreign(['workspace_id', 'offer_version_id'], 'gtm_conversations_workspace_offer_fk')
                ->references(['workspace_id', 'id'])->on('offer_versions')->restrictOnDelete();
            $table->foreign('owner_user_id', 'gtm_conversations_owner_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['workspace_id', 'fulfilment_request_id'], 'gtm_conversations_workspace_fulfilment_fk')
                ->references(['workspace_id', 'id'])->on('fulfilment_requests')->restrictOnDelete();
            $table->unique(['workspace_id', 'thread_id'], 'gtm_conversations_thread_unique');
            $table->index(['workspace_id', 'objective_version_id', 'state'], 'gtm_conversations_objective_state_index');
        });

        Schema::create('gtm_conversation_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('gtm_conversation_id');
            $table->foreignId('thread_id');
            $table->foreignId('message_id')->nullable();
            $table->string('event', 32);
            $table->string('classification', 32)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('source', 24)->nullable();
            $table->string('model_version', 160)->nullable();
            $table->string('prompt_version', 80)->nullable();
            $table->uuid('prior_event_id')->nullable();
            $table->string('safe_rationale', 1000)->nullable();
            $table->foreignId('actor_user_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at');
            $table->char('event_fingerprint', 64);
            $table->timestamp('created_at');

            $table->unique(['workspace_id', 'id'], 'gtm_conversation_events_workspace_id_unique');
            $table->foreign(['workspace_id', 'gtm_conversation_id'], 'gtm_conversation_events_workspace_conversation_fk')
                ->references(['workspace_id', 'id'])->on('gtm_conversations')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'thread_id'], 'gtm_conversation_events_workspace_thread_fk')
                ->references(['workspace_id', 'id'])->on('threads')->restrictOnDelete();
            $table->foreign(['workspace_id', 'message_id'], 'gtm_conversation_events_workspace_message_fk')
                ->references(['workspace_id', 'id'])->on('messages')->restrictOnDelete();
            $table->foreign(['workspace_id', 'prior_event_id'], 'gtm_conversation_events_workspace_prior_fk')
                ->references(['workspace_id', 'id'])->on('gtm_conversation_events')->restrictOnDelete();
            $table->foreign('actor_user_id', 'gtm_conversation_events_actor_fk')->references('id')->on('users')->nullOnDelete();
            $table->unique(['workspace_id', 'event_fingerprint'], 'gtm_conversation_events_fingerprint_unique');
            $table->index(['workspace_id', 'gtm_conversation_id', 'occurred_at'], 'gtm_conversation_events_time_index');
        });

        Schema::create('attribution_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('conversion_event_id');
            $table->uuid('objective_id');
            $table->uuid('objective_version_id');
            $table->uuid('conversion_definition_version_id');
            $table->uuid('selected_exposure_id')->nullable();
            $table->uuid('prior_assessment_id')->nullable();
            $table->string('state', 24);
            $table->string('method', 32)->nullable();
            $table->timestamp('window_started_at');
            $table->timestamp('window_ended_at');
            $table->unsignedInteger('candidate_count')->default(0);
            $table->string('rules_version', 48);
            $table->char('input_hash', 64);
            $table->char('candidate_hash', 64);
            $table->char('content_hash', 64);
            $table->json('reason_codes');
            $table->string('actor_type', 16)->default('system');
            $table->foreignId('actor_user_id')->nullable();
            $table->char('idempotency_fingerprint', 64);
            $table->timestamp('assessed_at');
            $table->timestamp('created_at');

            $table->unique(['workspace_id', 'id'], 'attribution_assessments_workspace_id_unique');
            $table->foreign(['workspace_id', 'conversion_event_id'], 'attribution_assessments_workspace_event_fk')
                ->references(['workspace_id', 'id'])->on('conversion_events')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'objective_id'], 'attribution_assessments_workspace_objective_fk')
                ->references(['workspace_id', 'id'])->on('objectives')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'objective_version_id'], 'attribution_assessments_workspace_version_fk')
                ->references(['workspace_id', 'id'])->on('objective_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'conversion_definition_version_id'], 'attribution_assessments_workspace_definition_fk')
                ->references(['workspace_id', 'id'])->on('conversion_definition_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'selected_exposure_id'], 'attribution_assessments_workspace_exposure_fk')
                ->references(['workspace_id', 'id'])->on('exposures')->restrictOnDelete();
            $table->foreign(['workspace_id', 'prior_assessment_id'], 'attribution_assessments_workspace_prior_fk')
                ->references(['workspace_id', 'id'])->on('attribution_assessments')->restrictOnDelete();
            $table->foreign('actor_user_id', 'attribution_assessments_actor_fk')->references('id')->on('users')->nullOnDelete();
            $table->unique(['workspace_id', 'idempotency_fingerprint'], 'attribution_assessments_idempotency_unique');
            $table->index(['workspace_id', 'conversion_event_id', 'assessed_at'], 'attribution_assessments_event_time_index');
            $table->index(['workspace_id', 'objective_version_id', 'state'], 'attribution_assessments_objective_state_index');
        });

        Schema::create('attribution_assessment_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('attribution_assessment_id');
            $table->uuid('exposure_id');
            $table->boolean('eligible');
            $table->json('reason_codes');
            $table->unsignedBigInteger('time_distance_seconds');
            $table->boolean('explicit_correlation')->default(false);
            $table->timestamp('created_at');

            $table->unique(['workspace_id', 'id'], 'attribution_candidates_workspace_id_unique');
            $table->foreign(['workspace_id', 'attribution_assessment_id'], 'attribution_candidates_workspace_assessment_fk')
                ->references(['workspace_id', 'id'])->on('attribution_assessments')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'exposure_id'], 'attribution_candidates_workspace_exposure_fk')
                ->references(['workspace_id', 'id'])->on('exposures')->restrictOnDelete();
            $table->unique(['workspace_id', 'attribution_assessment_id', 'exposure_id'], 'attribution_candidates_assessment_exposure_unique');
        });

        Schema::create('objective_outcome_progress', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('objective_id');
            $table->uuid('objective_version_id');
            $table->string('target_kind', 16);
            $table->unsignedInteger('target_count')->nullable();
            $table->decimal('target_value', 20, 6)->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedInteger('attributed_count')->default(0);
            $table->decimal('attributed_value', 20, 6)->default(0);
            $table->decimal('completion_percent', 8, 4)->default(0);
            $table->char('input_hash', 64);
            $table->timestamp('rebuilt_at');
            $table->timestamps();

            $table->foreign(['workspace_id', 'objective_id'], 'objective_progress_workspace_objective_fk')
                ->references(['workspace_id', 'id'])->on('objectives')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'objective_version_id'], 'objective_progress_workspace_version_fk')
                ->references(['workspace_id', 'id'])->on('objective_versions')->cascadeOnDelete();
            $table->unique(['workspace_id', 'objective_version_id'], 'objective_progress_version_unique');
            $table->index(['workspace_id', 'objective_id'], 'objective_progress_objective_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objective_outcome_progress');
        Schema::dropIfExists('attribution_assessment_candidates');
        Schema::dropIfExists('attribution_assessments');
        Schema::dropIfExists('gtm_conversation_events');
        Schema::dropIfExists('gtm_conversations');
        Schema::dropIfExists('exposures');

        $this->dropOutcomeOwnershipKeys();
    }

    private function addOutcomeOwnershipKeys(): void
    {
        foreach ([
            'execution_action_events' => 'odg_s3_execution_events_workspace_id_unique',
            'threads' => 'odg_s3_threads_workspace_id_unique',
            'messages' => 'odg_s3_messages_workspace_id_unique',
            'send_attempts' => 'odg_s3_send_attempts_workspace_id_unique',
        ] as $tableName => $indexName) {
            if (! Schema::hasIndex($tableName, $indexName)) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                    $table->unique(['workspace_id', 'id'], $indexName);
                });
            }
        }
    }

    private function dropOutcomeOwnershipKeys(): void
    {
        // InnoDB can discard the original FK-support index after the composite
        // unique is added. Restore it before removing the replacement: the
        // pre-S3 messages.workspace_id foreign key still needs an index.
        if (! Schema::hasIndex('messages', 'messages_workspace_id_index')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->index('workspace_id', 'messages_workspace_id_index');
            });
        }

        foreach (array_reverse([
            'execution_action_events' => 'odg_s3_execution_events_workspace_id_unique',
            'threads' => 'odg_s3_threads_workspace_id_unique',
            'messages' => 'odg_s3_messages_workspace_id_unique',
            'send_attempts' => 'odg_s3_send_attempts_workspace_id_unique',
        ], true) as $tableName => $indexName) {
            if (Schema::hasIndex($tableName, $indexName)) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                    $table->dropUnique($indexName);
                });
            }
        }
    }
};
