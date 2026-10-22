<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_compositions', fn (Blueprint $table) => $table->unique(['workspace_id', 'id'], 'compositions_workspace_id_unique'));
        Schema::table('objective_runs', function (Blueprint $table): void {
            $table->string('origin', 16)->default('ai');
            $table->uuid('campaign_composition_id')->nullable();
            $table->foreign(['workspace_id', 'campaign_composition_id'], 'runs_composition_fk')
                ->references(['workspace_id', 'id'])->on('campaign_compositions')->restrictOnDelete();
            $table->string('message_validator_version', 40)->nullable()->change();
        });
        Schema::table('objective_run_arm_drafts', function (Blueprint $table): void {
            $table->string('origin', 16)->default('ai');
            $table->uuid('ai_budget_run_id')->nullable()->change();
            $table->string('prompt_version', 40)->nullable()->change();
            $table->string('model_id', 160)->nullable()->change();
            $table->unsignedInteger('task_policy_version')->nullable()->change();
            $table->foreignId('authored_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('authored_at')->nullable();
        });
        Schema::table('execution_plans', function (Blueprint $table): void {
            $table->string('origin', 16)->default('ai');
            $table->string('model_id', 160)->nullable()->change();
            $table->string('ai_task_version', 40)->nullable()->change();
            $table->string('prompt_version', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Never erase human provenance or fabricate AI metadata to roll back.
        foreach (['objective_runs', 'objective_run_arm_drafts', 'execution_plans'] as $name) {
            if (DB::table($name)->where('origin', '!=', 'ai')->exists()) {
                throw new RuntimeException('Human execution records must be retained; this migration cannot be rolled back with human data.');
            }
        }
        foreach ([
            'objective_runs' => ['message_validator_version'],
            'objective_run_arm_drafts' => ['ai_budget_run_id', 'prompt_version', 'model_id', 'task_policy_version'],
            'execution_plans' => ['model_id', 'ai_task_version', 'prompt_version'],
        ] as $name => $fields) {
            foreach ($fields as $field) {
                if (DB::table($name)->whereNull($field)->exists()) {
                    throw new RuntimeException('Rollback requires complete legacy AI metadata; no schema changes were attempted.');
                }
            }
        }
        Schema::table('execution_plans', function (Blueprint $table): void {
            $table->string('model_id', 160)->nullable(false)->change();
            $table->string('ai_task_version', 40)->nullable(false)->change();
            $table->string('prompt_version', 40)->nullable(false)->change();
            $table->dropColumn('origin');
        });
        Schema::table('objective_run_arm_drafts', function (Blueprint $table): void {
            $table->uuid('ai_budget_run_id')->nullable(false)->change();
            $table->string('prompt_version', 40)->nullable(false)->change();
            $table->string('model_id', 160)->nullable(false)->change();
            $table->unsignedInteger('task_policy_version')->nullable(false)->change();
            $table->dropForeign(['authored_by_user_id']);
            $table->dropColumn(['origin', 'authored_by_user_id', 'authored_at']);
        });
        Schema::table('objective_runs', function (Blueprint $table): void {
            $table->string('message_validator_version', 40)->nullable(false)->change();
            $table->dropForeign('runs_composition_fk');
            $table->dropColumn(['origin', 'campaign_composition_id']);
        });
        Schema::table('campaign_compositions', fn (Blueprint $table) => $table->dropUnique('compositions_workspace_id_unique'));
    }
};
