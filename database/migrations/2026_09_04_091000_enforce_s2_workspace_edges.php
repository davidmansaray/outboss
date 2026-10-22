<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        Schema::table('gtm_profile_versions', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->nullable()->after('id');
        });
        foreach (DB::table('gtm_profiles')->pluck('workspace_id', 'id') as $profileId => $workspaceId) {
            DB::table('gtm_profile_versions')->where('gtm_profile_id', $profileId)->update(['workspace_id' => $workspaceId]);
        }
        Schema::table('gtm_profile_versions', function (Blueprint $table) use ($sqlite): void {
            $table->foreignId('workspace_id')->nullable(false)->change();
            if (! $sqlite) {
                $table->foreign('workspace_id', 'gtm_profile_versions_workspace_fk')
                    ->references('id')->on('workspaces')->restrictOnDelete();
            }
            $table->unique(['workspace_id', 'id'], 'gtm_profile_versions_workspace_id_unique');
        });

        // SQLite remains the fast application-test database. The supported
        // MySQL/MariaDB gate below is where composite tenant FKs are enforced
        // and explicitly exercised.
        if ($sqlite) {
            return;
        }

        $this->addAiParentKeys();
        $this->replaceAiEdges();
        $this->replaceIntelligenceEdges();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('gtm_profile_versions', function (Blueprint $table): void {
                $table->dropUnique('gtm_profile_versions_workspace_id_unique');
                $table->dropColumn('workspace_id');
            });

            return;
        }

        $this->restoreIntelligenceEdges();
        $this->restoreAiEdges();

        Schema::table('ai_provider_operations', function (Blueprint $table): void {
            $table->dropUnique('ai_provider_operations_workspace_id_unique');
        });
        Schema::table('ai_provider_credential_versions', function (Blueprint $table): void {
            $table->dropUnique('ai_credential_versions_credential_id_unique');
        });
        Schema::table('ai_provider_credentials', function (Blueprint $table): void {
            $table->dropUnique('ai_provider_credentials_workspace_id_unique');
        });
        Schema::table('ai_budget_runs', function (Blueprint $table): void {
            $table->dropUnique('ai_budget_runs_workspace_id_unique');
        });
        Schema::table('ai_budget_periods', function (Blueprint $table): void {
            $table->dropUnique('ai_budget_periods_workspace_id_unique');
        });
        Schema::table('gtm_profile_versions', function (Blueprint $table): void {
            $table->dropForeign('gtm_profile_versions_workspace_fk');
            $table->dropUnique('gtm_profile_versions_workspace_id_unique');
            $table->dropColumn('workspace_id');
        });
    }

    private function addAiParentKeys(): void
    {
        Schema::table('ai_budget_periods', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'id'], 'ai_budget_periods_workspace_id_unique');
        });
        Schema::table('ai_budget_runs', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'id'], 'ai_budget_runs_workspace_id_unique');
        });
        Schema::table('ai_provider_credentials', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'id'], 'ai_provider_credentials_workspace_id_unique');
        });
        Schema::table('ai_provider_credential_versions', function (Blueprint $table): void {
            $table->unique(['credential_id', 'id'], 'ai_credential_versions_credential_id_unique');
        });
        Schema::table('ai_provider_operations', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'id'], 'ai_provider_operations_workspace_id_unique');
        });
    }

    private function replaceAiEdges(): void
    {
        Schema::table('ai_budget_runs', function (Blueprint $table): void {
            $table->dropForeign(['budget_envelope_id']);
            $table->foreign(['workspace_id', 'budget_envelope_id'], 'ai_budget_runs_workspace_envelope_fk')
                ->references(['workspace_id', 'id'])->on('ai_budget_envelopes')->restrictOnDelete();
        });
        Schema::table('ai_provider_operations', function (Blueprint $table): void {
            $table->dropForeign(['budget_period_id']);
            $table->dropForeign(['budget_run_id']);
            $table->dropForeign(['budget_envelope_id']);
            $table->dropForeign(['credential_id']);
            $table->dropForeign(['credential_version_id']);
            $table->foreign(['workspace_id', 'budget_period_id'], 'ai_ops_workspace_period_fk')
                ->references(['workspace_id', 'id'])->on('ai_budget_periods')->restrictOnDelete();
            $table->foreign(['workspace_id', 'budget_run_id'], 'ai_ops_workspace_run_fk')
                ->references(['workspace_id', 'id'])->on('ai_budget_runs')->restrictOnDelete();
            $table->foreign(['workspace_id', 'budget_envelope_id'], 'ai_ops_workspace_envelope_fk')
                ->references(['workspace_id', 'id'])->on('ai_budget_envelopes')->restrictOnDelete();
            $table->foreign(['workspace_id', 'credential_id'], 'ai_ops_workspace_credential_fk')
                ->references(['workspace_id', 'id'])->on('ai_provider_credentials')->restrictOnDelete();
            $table->foreign(['credential_id', 'credential_version_id'], 'ai_ops_credential_version_fk')
                ->references(['credential_id', 'id'])->on('ai_provider_credential_versions')->restrictOnDelete();
        });
    }

    private function replaceIntelligenceEdges(): void
    {
        Schema::table('account_research_runs', function (Blueprint $table): void {
            $table->dropForeign('account_research_runs_gtm_version_fk');
            $table->dropForeign('account_research_runs_offer_version_fk');
            $table->dropForeign('account_research_runs_research_budget_fk');
            $table->dropForeign('account_research_runs_qualification_budget_fk');
            $table->foreign(['workspace_id', 'gtm_profile_version_id'], 'acct_runs_workspace_gtm_fk')
                ->references(['workspace_id', 'id'])->on('gtm_profile_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'offer_version_id'], 'acct_runs_workspace_offer_fk')
                ->references(['workspace_id', 'id'])->on('offer_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'budget_envelope_id'], 'acct_runs_workspace_envelope_fk')
                ->references(['workspace_id', 'id'])->on('ai_budget_envelopes')->restrictOnDelete();
            $table->foreign(['workspace_id', 'research_budget_run_id'], 'acct_runs_workspace_research_budget_fk')
                ->references(['workspace_id', 'id'])->on('ai_budget_runs')->restrictOnDelete();
            $table->foreign(['workspace_id', 'qualification_budget_run_id'], 'acct_runs_workspace_qualification_budget_fk')
                ->references(['workspace_id', 'id'])->on('ai_budget_runs')->restrictOnDelete();
        });
        Schema::table('account_research_attempts', function (Blueprint $table): void {
            $table->dropForeign('account_research_attempts_provider_operation_fk');
            $table->foreign(['workspace_id', 'provider_operation_id'], 'acct_attempts_workspace_operation_fk')
                ->references(['workspace_id', 'id'])->on('ai_provider_operations')->restrictOnDelete();
        });
        Schema::table('research_source_snapshots', function (Blueprint $table): void {
            $table->dropForeign('research_snapshots_provider_operation_fk');
            $table->foreign(['workspace_id', 'provider_operation_id'], 'research_snapshots_workspace_operation_fk')
                ->references(['workspace_id', 'id'])->on('ai_provider_operations')->restrictOnDelete();
        });
        Schema::table('qualification_rubric_versions', function (Blueprint $table): void {
            $table->dropForeign('qualification_rubric_versions_gtm_version_fk');
            $table->dropForeign('qualification_rubric_versions_offer_version_fk');
            $table->foreign(['workspace_id', 'gtm_profile_version_id'], 'rubric_versions_workspace_gtm_fk')
                ->references(['workspace_id', 'id'])->on('gtm_profile_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'offer_version_id'], 'rubric_versions_workspace_offer_fk')
                ->references(['workspace_id', 'id'])->on('offer_versions')->restrictOnDelete();
        });
        Schema::table('qualification_decisions', function (Blueprint $table): void {
            $table->dropForeign('qualification_decisions_gtm_version_fk');
            $table->dropForeign('qualification_decisions_offer_version_fk');
            $table->dropForeign('qualification_decisions_provider_operation_fk');
            $table->foreign(['workspace_id', 'gtm_profile_version_id'], 'qualification_workspace_gtm_fk')
                ->references(['workspace_id', 'id'])->on('gtm_profile_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'offer_version_id'], 'qualification_workspace_offer_fk')
                ->references(['workspace_id', 'id'])->on('offer_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'provider_operation_id'], 'qualification_workspace_operation_fk')
                ->references(['workspace_id', 'id'])->on('ai_provider_operations')->restrictOnDelete();
        });
    }

    private function restoreIntelligenceEdges(): void
    {
        Schema::table('qualification_decisions', function (Blueprint $table): void {
            $table->dropForeign('qualification_workspace_gtm_fk');
            $table->dropForeign('qualification_workspace_offer_fk');
            $table->dropForeign('qualification_workspace_operation_fk');
            $table->foreign('gtm_profile_version_id', 'qualification_decisions_gtm_version_fk')->references('id')->on('gtm_profile_versions')->restrictOnDelete();
            $table->foreign('offer_version_id', 'qualification_decisions_offer_version_fk')->references('id')->on('offer_versions')->restrictOnDelete();
            $table->foreign('provider_operation_id', 'qualification_decisions_provider_operation_fk')->references('id')->on('ai_provider_operations')->nullOnDelete();
        });
        Schema::table('qualification_rubric_versions', function (Blueprint $table): void {
            $table->dropForeign('rubric_versions_workspace_gtm_fk');
            $table->dropForeign('rubric_versions_workspace_offer_fk');
            $table->foreign('gtm_profile_version_id', 'qualification_rubric_versions_gtm_version_fk')->references('id')->on('gtm_profile_versions')->restrictOnDelete();
            $table->foreign('offer_version_id', 'qualification_rubric_versions_offer_version_fk')->references('id')->on('offer_versions')->restrictOnDelete();
        });
        Schema::table('research_source_snapshots', function (Blueprint $table): void {
            $table->dropForeign('research_snapshots_workspace_operation_fk');
            $table->foreign('provider_operation_id', 'research_snapshots_provider_operation_fk')->references('id')->on('ai_provider_operations')->nullOnDelete();
        });
        Schema::table('account_research_attempts', function (Blueprint $table): void {
            $table->dropForeign('acct_attempts_workspace_operation_fk');
            $table->foreign('provider_operation_id', 'account_research_attempts_provider_operation_fk')->references('id')->on('ai_provider_operations')->nullOnDelete();
        });
        Schema::table('account_research_runs', function (Blueprint $table): void {
            $table->dropForeign('acct_runs_workspace_gtm_fk');
            $table->dropForeign('acct_runs_workspace_offer_fk');
            $table->dropForeign('acct_runs_workspace_envelope_fk');
            $table->dropForeign('acct_runs_workspace_research_budget_fk');
            $table->dropForeign('acct_runs_workspace_qualification_budget_fk');
            $table->foreign('gtm_profile_version_id', 'account_research_runs_gtm_version_fk')->references('id')->on('gtm_profile_versions')->restrictOnDelete();
            $table->foreign('offer_version_id', 'account_research_runs_offer_version_fk')->references('id')->on('offer_versions')->restrictOnDelete();
            $table->foreign('research_budget_run_id', 'account_research_runs_research_budget_fk')->references('id')->on('ai_budget_runs')->restrictOnDelete();
            $table->foreign('qualification_budget_run_id', 'account_research_runs_qualification_budget_fk')->references('id')->on('ai_budget_runs')->restrictOnDelete();
        });
    }

    private function restoreAiEdges(): void
    {
        Schema::table('ai_provider_operations', function (Blueprint $table): void {
            $table->dropForeign('ai_ops_workspace_period_fk');
            $table->dropForeign('ai_ops_workspace_run_fk');
            $table->dropForeign('ai_ops_workspace_envelope_fk');
            $table->dropForeign('ai_ops_workspace_credential_fk');
            $table->dropForeign('ai_ops_credential_version_fk');
            $table->foreign('budget_period_id')->references('id')->on('ai_budget_periods')->restrictOnDelete();
            $table->foreign('budget_run_id')->references('id')->on('ai_budget_runs')->restrictOnDelete();
            $table->foreign('budget_envelope_id')->references('id')->on('ai_budget_envelopes')->restrictOnDelete();
            $table->foreign('credential_id')->references('id')->on('ai_provider_credentials')->restrictOnDelete();
            $table->foreign('credential_version_id')->references('id')->on('ai_provider_credential_versions')->restrictOnDelete();
        });
        Schema::table('ai_budget_runs', function (Blueprint $table): void {
            $table->dropForeign('ai_budget_runs_workspace_envelope_fk');
            $table->foreign('budget_envelope_id')->references('id')->on('ai_budget_envelopes')->restrictOnDelete();
        });
    }
};
