<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('event_key', 100);
            $table->uuid('current_version_id')->nullable();
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'event_key'], 'conversion_definitions_workspace_event_key_unique');
            $table->unique(['workspace_id', 'id'], 'conversion_definitions_workspace_id_unique');
            $table->unique(
                ['workspace_id', 'id', 'current_version_id'],
                'conversion_definitions_workspace_current_version_unique',
            );
            $table->index(['workspace_id', 'status'], 'conversion_definitions_workspace_status_index');
        });

        Schema::create('conversion_definition_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversion_definition_id')
                ->constrained('conversion_definitions')
                ->restrictOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('schema_version')->default(1);
            $table->string('display_name', 160);
            $table->text('description')->nullable();
            $table->string('subject_type', 32);
            $table->json('allowed_sources');
            $table->json('property_schema');
            $table->json('allowed_identity_keys');
            $table->string('dedupe_strategy', 48);
            $table->json('dedupe_configuration');
            $table->json('attribution_window');
            $table->json('value_policy');
            $table->json('correction_policy');
            $table->json('reversal_policy');
            $table->json('retention_policy');
            $table->json('export_policy');
            $table->char('content_hash', 64);
            $table->string('origin', 40);
            $table->string('actor_type', 32)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_display_name', 160)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['conversion_definition_id', 'version'],
                'conversion_definition_versions_definition_version_unique',
            );
            $table->unique(
                ['conversion_definition_id', 'id'],
                'conversion_definition_versions_definition_id_unique',
            );
            $table->unique(
                ['workspace_id', 'conversion_definition_id', 'id'],
                'conversion_definition_versions_workspace_definition_id_unique',
            );
            $table->index(
                ['workspace_id', 'conversion_definition_id', 'created_at'],
                'conversion_definition_versions_workspace_created_index',
            );
        });

        Schema::table('conversion_definition_versions', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'conversion_definition_id'],
                'conversion_definition_versions_workspace_definition_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('conversion_definitions')
                ->restrictOnDelete();
        });

        Schema::table('conversion_definitions', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'id', 'current_version_id'],
                'conversion_definitions_current_version_fk',
            )
                ->references(['workspace_id', 'conversion_definition_id', 'id'])
                ->on('conversion_definition_versions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversion_definitions', function (Blueprint $table): void {
            $table->dropForeign('conversion_definitions_current_version_fk');
        });
        Schema::table('conversion_definition_versions', function (Blueprint $table): void {
            $table->dropForeign('conversion_definition_versions_workspace_definition_fk');
        });
        Schema::dropIfExists('conversion_definition_versions');
        Schema::dropIfExists('conversion_definitions');
    }
};
