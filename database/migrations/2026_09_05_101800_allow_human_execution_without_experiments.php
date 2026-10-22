<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FIELDS = [
        'objective_runs' => ['gtm_experiment_id'],
        'objective_run_arm_drafts' => ['gtm_experiment_arm_id'],
        'execution_plans' => ['gtm_experiment_id'],
        'execution_plan_actions' => ['gtm_experiment_assignment_id', 'gtm_experiment_arm_id', 'strategy_version_id'],
        'execution_plan_campaigns' => ['gtm_experiment_arm_id'],
        'exposures' => ['gtm_experiment_assignment_id', 'gtm_experiment_arm_id', 'strategy_version_id'],
    ];

    public function up(): void
    {
        foreach (self::FIELDS as $name => $fields) {
            Schema::table($name, function (Blueprint $table) use ($fields): void {
                foreach ($fields as $field) {
                    $table->uuid($field)->nullable()->change();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::FIELDS as $name => $fields) {
            foreach ($fields as $field) {
                if (DB::table($name)->whereNull($field)->exists()) {
                    throw new RuntimeException('Human execution lineage must be retained; rollback cannot invent experiment identities.');
                }
            }
        }
        foreach (self::FIELDS as $name => $fields) {
            Schema::table($name, function (Blueprint $table) use ($fields): void {
                foreach ($fields as $field) {
                    $table->uuid($field)->nullable(false)->change();
                }
            });
        }
    }
};
