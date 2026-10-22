<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addCompilationOwnershipKeys();

        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->string('governed_subject_snapshot', 160)->nullable();
            $table->longText('governed_body_text_snapshot')->nullable();
        });

        Schema::create('execution_plan_compilations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('execution_plan_id');
            // Nullable uniqueness gives MySQL and SQLite one successful row
            // per plan while still permitting append-only failed attempts.
            $table->uuid('succeeded_plan_id')->nullable();
            $table->unsignedInteger('attempt');
            $table->string('state', 24)->default('started');
            $table->char('input_hash', 64);
            $table->char('output_hash', 64)->nullable();
            $table->string('safe_error_code', 80)->nullable();
            $table->string('safe_error_detail', 500)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'execution_plan_id'], 'plan_compilations_workspace_plan_fk')
                ->references(['workspace_id', 'id'])->on('execution_plans')->restrictOnDelete();
            $table->foreign(['workspace_id', 'succeeded_plan_id'], 'plan_compilations_workspace_success_fk')
                ->references(['workspace_id', 'id'])->on('execution_plans')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'plan_compilations_workspace_id_unique');
            $table->unique(
                ['workspace_id', 'id', 'execution_plan_id'],
                'plan_compilations_workspace_id_plan_unique',
            );
            $table->unique(
                ['workspace_id', 'execution_plan_id', 'attempt'],
                'plan_compilations_plan_attempt_unique',
            );
            $table->unique(
                ['workspace_id', 'succeeded_plan_id'],
                'plan_compilations_success_plan_unique',
            );
            $table->index(['workspace_id', 'execution_plan_id', 'state'], 'plan_compilations_plan_state_index');
            $table->index(['workspace_id', 'created_at'], 'plan_compilations_workspace_created_index');
        });

        Schema::create('execution_plan_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('execution_plan_compilation_id');
            $table->uuid('execution_plan_id');
            $table->uuid('gtm_experiment_arm_id');
            $table->foreignId('campaign_id');
            $table->foreignId('sequence_version_id');
            $table->foreignId('sequence_step_id');
            $table->timestamps();

            $table->foreign(
                ['workspace_id', 'execution_plan_compilation_id', 'execution_plan_id'],
                'plan_campaigns_workspace_compilation_plan_fk',
            )->references(['workspace_id', 'id', 'execution_plan_id'])
                ->on('execution_plan_compilations')->restrictOnDelete();
            $table->foreign(['workspace_id', 'execution_plan_id'], 'plan_campaigns_workspace_plan_fk')
                ->references(['workspace_id', 'id'])->on('execution_plans')->restrictOnDelete();
            $table->foreign(['workspace_id', 'gtm_experiment_arm_id'], 'plan_campaigns_workspace_arm_fk')
                ->references(['workspace_id', 'id'])->on('gtm_experiment_arms')->restrictOnDelete();
            $table->foreign(['workspace_id', 'campaign_id'], 'plan_campaigns_workspace_campaign_fk')
                ->references(['workspace_id', 'id'])->on('campaigns')->restrictOnDelete();
            $table->foreign(['campaign_id', 'sequence_version_id'], 'plan_campaigns_campaign_version_fk')
                ->references(['campaign_id', 'id'])->on('sequence_versions')->restrictOnDelete();
            $table->foreign(['sequence_version_id', 'sequence_step_id'], 'plan_campaigns_version_step_fk')
                ->references(['sequence_version_id', 'id'])->on('sequence_steps')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'plan_campaigns_workspace_id_unique');
            $table->unique(
                ['workspace_id', 'id', 'sequence_step_id'],
                'plan_campaigns_workspace_id_step_unique',
            );
            $table->unique(
                ['workspace_id', 'id', 'execution_plan_id', 'campaign_id', 'sequence_step_id'],
                'plan_campaigns_exact_output_unique',
            );
            $table->unique(
                ['workspace_id', 'execution_plan_id', 'gtm_experiment_arm_id'],
                'plan_campaigns_plan_arm_unique',
            );
            $table->unique(['workspace_id', 'campaign_id'], 'plan_campaigns_campaign_unique');
            $table->index(
                ['workspace_id', 'execution_plan_compilation_id'],
                'plan_campaigns_compilation_index',
            );
            $table->index(['workspace_id', 'created_at'], 'plan_campaigns_workspace_created_index');
        });

        Schema::create('execution_plan_action_compilations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('execution_plan_compilation_id');
            $table->uuid('execution_plan_id');
            $table->uuid('execution_plan_action_id');
            $table->uuid('execution_plan_campaign_id');
            $table->foreignId('campaign_id');
            $table->foreignId('campaign_recipient_id');
            $table->foreignId('sequence_step_id');
            $table->char('compiled_input_hash', 64);
            $table->char('rendered_content_hash', 64);
            $table->timestamps();

            $table->foreign(
                ['workspace_id', 'execution_plan_compilation_id', 'execution_plan_id'],
                'action_compilations_workspace_compilation_plan_fk',
            )->references(['workspace_id', 'id', 'execution_plan_id'])
                ->on('execution_plan_compilations')->restrictOnDelete();
            $table->foreign(['workspace_id', 'execution_plan_id'], 'action_compilations_workspace_plan_fk')
                ->references(['workspace_id', 'id'])->on('execution_plans')->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'execution_plan_action_id', 'execution_plan_id'],
                'action_compilations_workspace_action_plan_fk',
            )->references(['workspace_id', 'id', 'execution_plan_id'])
                ->on('execution_plan_actions')->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'execution_plan_campaign_id', 'execution_plan_id', 'campaign_id', 'sequence_step_id'],
                'action_compilations_exact_campaign_fk',
            )->references(['workspace_id', 'id', 'execution_plan_id', 'campaign_id', 'sequence_step_id'])
                ->on('execution_plan_campaigns')->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'campaign_id', 'campaign_recipient_id'],
                'action_compilations_workspace_campaign_recipient_fk',
            )->references(['workspace_id', 'campaign_id', 'id'])
                ->on('campaign_recipients')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'action_compilations_workspace_id_unique');
            $table->unique(
                ['workspace_id', 'execution_plan_action_id'],
                'action_compilations_action_unique',
            );
            $table->unique(
                ['workspace_id', 'campaign_recipient_id'],
                'action_compilations_recipient_unique',
            );
            $table->index(
                ['workspace_id', 'execution_plan_compilation_id'],
                'action_compilations_compilation_index',
            );
            $table->index(['workspace_id', 'created_at'], 'action_compilations_workspace_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_plan_action_compilations');
        Schema::dropIfExists('execution_plan_campaigns');
        Schema::dropIfExists('execution_plan_compilations');

        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->dropColumn(['governed_subject_snapshot', 'governed_body_text_snapshot']);
        });

        $this->dropCompilationOwnershipKeys();
    }

    private function addCompilationOwnershipKeys(): void
    {
        if (! Schema::hasIndex('campaigns', 'odg_s3_campaigns_workspace_id_unique')) {
            Schema::table('campaigns', function (Blueprint $table): void {
                $table->unique(['workspace_id', 'id'], 'odg_s3_campaigns_workspace_id_unique');
            });
        }
        if (! Schema::hasIndex('campaign_recipients', 'odg_s3_campaign_recipients_workspace_id_unique')) {
            Schema::table('campaign_recipients', function (Blueprint $table): void {
                $table->unique(['workspace_id', 'id'], 'odg_s3_campaign_recipients_workspace_id_unique');
            });
        }
        if (! Schema::hasIndex('campaign_recipients', 'odg_s3_campaign_recipients_owner_unique')) {
            Schema::table('campaign_recipients', function (Blueprint $table): void {
                $table->unique(
                    ['workspace_id', 'campaign_id', 'id'],
                    'odg_s3_campaign_recipients_owner_unique',
                );
            });
        }
        if (! Schema::hasIndex('execution_plan_actions', 'odg_s3_plan_actions_workspace_id_plan_unique')) {
            Schema::table('execution_plan_actions', function (Blueprint $table): void {
                $table->unique(
                    ['workspace_id', 'id', 'execution_plan_id'],
                    'odg_s3_plan_actions_workspace_id_plan_unique',
                );
            });
        }
        if (! Schema::hasIndex('sequence_versions', 'odg_s3_sequence_versions_campaign_id_unique')) {
            Schema::table('sequence_versions', function (Blueprint $table): void {
                $table->unique(['campaign_id', 'id'], 'odg_s3_sequence_versions_campaign_id_unique');
            });
        }
        if (! Schema::hasIndex('sequence_steps', 'odg_s3_sequence_steps_version_id_unique')) {
            Schema::table('sequence_steps', function (Blueprint $table): void {
                $table->unique(['sequence_version_id', 'id'], 'odg_s3_sequence_steps_version_id_unique');
            });
        }
    }

    private function dropCompilationOwnershipKeys(): void
    {
        if (Schema::hasIndex('execution_plan_actions', 'odg_s3_plan_actions_workspace_id_plan_unique')) {
            Schema::table('execution_plan_actions', function (Blueprint $table): void {
                $table->dropUnique('odg_s3_plan_actions_workspace_id_plan_unique');
            });
        }
        if (Schema::hasIndex('campaign_recipients', 'odg_s3_campaign_recipients_owner_unique')) {
            Schema::table('campaign_recipients', function (Blueprint $table): void {
                $table->dropUnique('odg_s3_campaign_recipients_owner_unique');
            });
        }
        if (Schema::hasIndex('sequence_steps', 'odg_s3_sequence_steps_version_id_unique')) {
            Schema::table('sequence_steps', function (Blueprint $table): void {
                $table->dropUnique('odg_s3_sequence_steps_version_id_unique');
            });
        }
        if (Schema::hasIndex('sequence_versions', 'odg_s3_sequence_versions_campaign_id_unique')) {
            Schema::table('sequence_versions', function (Blueprint $table): void {
                $table->dropUnique('odg_s3_sequence_versions_campaign_id_unique');
            });
        }
        if (Schema::hasIndex('campaign_recipients', 'odg_s3_campaign_recipients_workspace_id_unique')) {
            Schema::table('campaign_recipients', function (Blueprint $table): void {
                $table->dropUnique('odg_s3_campaign_recipients_workspace_id_unique');
            });
        }
        if (Schema::hasIndex('campaigns', 'odg_s3_campaigns_workspace_id_unique')) {
            Schema::table('campaigns', function (Blueprint $table): void {
                $table->dropUnique('odg_s3_campaigns_workspace_id_unique');
            });
        }
    }
};
