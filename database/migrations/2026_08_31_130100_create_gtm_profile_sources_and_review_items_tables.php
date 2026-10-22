<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gtm_profile_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('gtm_profile_id')->constrained('gtm_profiles')->restrictOnDelete();
            $table->string('source_type', 32);
            $table->string('canonical_url', 2048);
            $table->string('title', 160)->nullable();
            $table->timestamp('observed_at');
            $table->timestamp('published_at')->nullable();
            $table->text('excerpt')->nullable();
            $table->char('content_hash', 64);
            $table->char('excerpt_hash', 64)->nullable();
            $table->uuid('provider_operation_id')->nullable();
            $table->uuid('research_run_id')->nullable();
            $table->timestamps();

            $table->foreign('provider_operation_id', 'gtm_sources_provider_operation_fk')
                ->references('id')
                ->on('ai_provider_operations')
                ->nullOnDelete();
            $table->index(['gtm_profile_id', 'observed_at'], 'gtm_sources_profile_observed_index');
            $table->index(['gtm_profile_id', 'source_type'], 'gtm_sources_profile_type_index');
            $table->unique(
                ['research_run_id', 'source_type', 'content_hash'],
                'gtm_sources_run_type_content_unique'
            );
            $table->index('provider_operation_id', 'gtm_sources_provider_operation_index');
            $table->index('research_run_id', 'gtm_sources_research_run_index');
        });

        Schema::create('gtm_profile_review_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('gtm_profile_id')->constrained('gtm_profiles')->restrictOnDelete();
            $table->string('field_path', 160);
            $table->string('type', 24);
            $table->json('proposed_value')->nullable();
            $table->json('source_ids')->nullable();
            $table->char('proposed_value_hash', 64)->nullable();
            $table->string('status', 24)->default('open');
            $table->json('resolved_value')->nullable();
            $table->uuid('resolved_version_id')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['gtm_profile_id', 'field_path', 'proposed_value_hash'],
                'gtm_review_profile_field_proposal_unique'
            );
            $table->index(['gtm_profile_id', 'status'], 'gtm_review_profile_status_index');
            $table->index(['gtm_profile_id', 'field_path'], 'gtm_review_profile_field_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gtm_profile_review_items');
        Schema::table('gtm_profile_sources', function (Blueprint $table): void {
            $table->dropForeign('gtm_sources_provider_operation_fk');
        });
        Schema::dropIfExists('gtm_profile_sources');
    }
};
