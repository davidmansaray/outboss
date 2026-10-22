<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gtm_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('current_version_id')->nullable();
            $table->string('research_state', 32)->default('not_started');
            $table->string('awaiting_reason', 24)->nullable();
            $table->uuid('current_research_run_id')->nullable();
            $table->timestamp('last_researched_at')->nullable();
            $table->timestamp('last_research_failed_at')->nullable();
            $table->string('safe_error_code', 64)->nullable();
            $table->string('last_researched_website_url', 2048)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique('workspace_id');
            $table->index(['research_state', 'last_researched_at'], 'gtm_profiles_state_researched_index');
        });

        Schema::create('gtm_profile_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('gtm_profile_id')->constrained('gtm_profiles')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('schema_version');
            $table->json('payload');
            $table->char('content_hash', 64);
            $table->string('actor_type', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_display_name', 160)->nullable();
            $table->string('origin', 40);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['gtm_profile_id', 'version'], 'gtm_profile_versions_profile_version_unique');
            $table->unique(['gtm_profile_id', 'id'], 'gtm_profile_versions_profile_id_unique');
            $table->index(['gtm_profile_id', 'created_at'], 'gtm_profile_versions_profile_created_index');
        });

        Schema::table('gtm_profiles', function (Blueprint $table): void {
            $table->foreign(['id', 'current_version_id'], 'gtm_profiles_current_version_fk')
                ->references(['gtm_profile_id', 'id'])
                ->on('gtm_profile_versions')
                ->restrictOnDelete();
        });

        Schema::create('offers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('current_version_id')->nullable();
            $table->string('status', 24)->default('draft');
            $table->string('active_name_normalized', 160)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'status'], 'offers_workspace_status_index');
            $table->unique(
                ['workspace_id', 'active_name_normalized'],
                'offers_workspace_active_name_unique'
            );
        });

        Schema::create('offer_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('offer_id')->constrained('offers')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('payload');
            $table->string('status_snapshot', 24);
            $table->char('content_hash', 64);
            $table->string('origin', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('actor_type', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_display_name', 160)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['offer_id', 'version'], 'offer_versions_offer_version_unique');
            $table->unique(['offer_id', 'id'], 'offer_versions_offer_id_unique');
            $table->index(['offer_id', 'created_at'], 'offer_versions_offer_created_index');
        });

        Schema::table('offers', function (Blueprint $table): void {
            $table->foreign(['id', 'current_version_id'], 'offers_current_version_fk')
                ->references(['offer_id', 'id'])
                ->on('offer_versions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropForeign('offers_current_version_fk');
        });
        Schema::dropIfExists('offer_versions');
        Schema::dropIfExists('offers');

        Schema::table('gtm_profiles', function (Blueprint $table): void {
            $table->dropForeign('gtm_profiles_current_version_fk');
        });
        Schema::dropIfExists('gtm_profile_versions');
        Schema::dropIfExists('gtm_profiles');
    }
};
