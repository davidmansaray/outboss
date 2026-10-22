<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep every denormalised GTM relationship inside its owning aggregate.
     *
     * The individual foreign keys created with the base tables still provide
     * useful referential integrity. These composite keys close the gap where
     * two valid rows from different profile/research aggregates could
     * otherwise be combined. The referenced ownership indexes are explicit so
     * this works on both MySQL and SQLite.
     */
    public function up(): void
    {
        Schema::table('gtm_profile_research_phases', function (Blueprint $table): void {
            $table->unique(
                ['research_run_id', 'id'],
                'gtm_research_phases_run_id_unique'
            );
        });

        Schema::table('gtm_profile_research_runs', function (Blueprint $table): void {
            $table->foreign(
                ['gtm_profile_id', 'parent_run_id'],
                'gtm_research_runs_parent_profile_fk'
            )
                ->references(['gtm_profile_id', 'id'])
                ->on('gtm_profile_research_runs')
                ->restrictOnDelete();
        });

        Schema::table('gtm_profile_research_attempts', function (Blueprint $table): void {
            $table->foreign(
                ['research_run_id', 'phase_id'],
                'gtm_research_attempts_phase_run_fk'
            )
                ->references(['research_run_id', 'id'])
                ->on('gtm_profile_research_phases')
                ->restrictOnDelete();
        });

        Schema::table('gtm_profile_research_dispatches', function (Blueprint $table): void {
            $table->foreign(
                ['gtm_profile_id', 'research_run_id'],
                'gtm_research_dispatch_profile_run_fk'
            )
                ->references(['gtm_profile_id', 'id'])
                ->on('gtm_profile_research_runs')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gtm_profile_research_dispatches', function (Blueprint $table): void {
            $table->dropForeign('gtm_research_dispatch_profile_run_fk');
        });

        Schema::table('gtm_profile_research_attempts', function (Blueprint $table): void {
            $table->dropForeign('gtm_research_attempts_phase_run_fk');
        });

        Schema::table('gtm_profile_research_runs', function (Blueprint $table): void {
            $table->dropForeign('gtm_research_runs_parent_profile_fk');
        });

        Schema::table('gtm_profile_research_phases', function (Blueprint $table): void {
            $table->dropUnique('gtm_research_phases_run_id_unique');
        });
    }
};
