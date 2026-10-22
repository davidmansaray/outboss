<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gtm_profiles', function (Blueprint $table): void {
            $table->foreign(['id', 'current_research_run_id'], 'gtm_profiles_current_research_run_fk')
                ->references(['gtm_profile_id', 'id'])
                ->on('gtm_profile_research_runs')
                ->restrictOnDelete();
        });

        Schema::table('gtm_profile_research_runs', function (Blueprint $table): void {
            $table->foreign(
                ['gtm_profile_id', 'base_profile_version_id'],
                'gtm_research_runs_base_version_fk'
            )
                ->references(['gtm_profile_id', 'id'])
                ->on('gtm_profile_versions')
                ->restrictOnDelete();
        });

        Schema::table('gtm_profile_sources', function (Blueprint $table): void {
            $table->foreign(['gtm_profile_id', 'research_run_id'], 'gtm_sources_research_run_fk')
                ->references(['gtm_profile_id', 'id'])
                ->on('gtm_profile_research_runs')
                ->restrictOnDelete();
        });

        Schema::table('gtm_profile_review_items', function (Blueprint $table): void {
            $table->foreign(['gtm_profile_id', 'resolved_version_id'], 'gtm_review_resolved_version_fk')
                ->references(['gtm_profile_id', 'id'])
                ->on('gtm_profile_versions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gtm_profile_review_items', function (Blueprint $table): void {
            $table->dropForeign('gtm_review_resolved_version_fk');
        });
        Schema::table('gtm_profile_sources', function (Blueprint $table): void {
            $table->dropForeign('gtm_sources_research_run_fk');
        });
        Schema::table('gtm_profile_research_runs', function (Blueprint $table): void {
            $table->dropForeign('gtm_research_runs_base_version_fk');
        });
        Schema::table('gtm_profiles', function (Blueprint $table): void {
            $table->dropForeign('gtm_profiles_current_research_run_fk');
        });
    }
};
