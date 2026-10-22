<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospect_sources', function (Blueprint $table): void {
            $table->timestamp('deletion_requested_at')->nullable();
            // This is deliberately a non-FK pointer. The source/version
            // relationship is already protected by the immutable lineage
            // tables, while a direct FK here would add another cycle to the
            // deferred same-aggregate constraints migration.
            $table->uuid('deletion_approved_version_id')->nullable();
            $table->char('deletion_approved_policy_hash', 64)->nullable();
            $table->string('deletion_approved_behavior', 32)->nullable();
            $table->boolean('deletion_delete_canonical')->default(false);

            $table->index(
                ['workspace_id', 'deletion_requested_at'],
                'prospect_sources_workspace_deletion_requested_index',
            );
        });

        Schema::create('prospect_source_deletion_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('prospect_source_id');
            $table->string('entity_type', 24);
            // Canonical account/person IDs are intentionally polymorphic. The
            // source row and workspace FK provide the ownership boundary; a
            // polymorphic target cannot be represented by one SQL FK.
            $table->unsignedBigInteger('entity_id');
            $table->timestamps();

            $table->foreign(
                ['workspace_id', 'prospect_source_id'],
                'prospect_source_deletion_targets_workspace_source_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('prospect_sources')
                ->cascadeOnDelete();

            $table->unique(
                ['workspace_id', 'prospect_source_id', 'entity_type', 'entity_id'],
                'prospect_source_deletion_targets_unique',
            );
            $table->index(
                ['workspace_id', 'prospect_source_id', 'entity_type'],
                'prospect_source_deletion_targets_source_type_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_source_deletion_targets');

        Schema::table('prospect_sources', function (Blueprint $table): void {
            $table->dropIndex('prospect_sources_workspace_deletion_requested_index');
            $table->dropColumn([
                'deletion_requested_at',
                'deletion_approved_version_id',
                'deletion_approved_policy_hash',
                'deletion_approved_behavior',
                'deletion_delete_canonical',
            ]);
        });
    }
};
