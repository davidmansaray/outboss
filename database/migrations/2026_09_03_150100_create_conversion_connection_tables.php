<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_source_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('kind', 24);
            $table->string('display_name', 160);
            $table->string('status', 24)->default('active');
            $table->uuid('current_version_id')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'conversion_connections_workspace_id_unique');
            $table->unique(['workspace_id', 'public_id'], 'conversion_connections_workspace_public_id_unique');
            $table->unique(
                ['workspace_id', 'id', 'current_version_id'],
                'conversion_connections_workspace_current_version_unique',
            );
            $table->index(['workspace_id', 'kind', 'status'], 'conversion_connections_workspace_status_index');
        });

        Schema::create('conversion_source_connection_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')
                ->constrained('conversion_source_connections')
                ->restrictOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('active');
            // This column is encrypted by the model cast. Plaintext is never
            // accepted by logs, exports, queues or response DTOs.
            $table->longText('encrypted_secret')->nullable();
            $table->json('allowed_conversion_definition_ids');
            $table->json('allowed_source_types');
            $table->string('signature_algorithm', 32)->default('hmac_sha256');
            $table->unsignedInteger('payload_limit_bytes')->default(262144);
            $table->unsignedInteger('replay_window_seconds')->default(300);
            $table->timestamp('valid_from')->useCurrent();
            $table->timestamp('overlap_until')->nullable();
            $table->json('ip_policy')->nullable();
            $table->json('rate_policy')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('wiped_at')->nullable();

            $table->unique(['connection_id', 'version'], 'conversion_connection_versions_connection_version_unique');
            $table->unique(['connection_id', 'id'], 'conversion_connection_versions_connection_id_unique');
            $table->unique(
                ['workspace_id', 'connection_id', 'id'],
                'conversion_connection_versions_workspace_connection_id_unique',
            );
            $table->index(
                ['workspace_id', 'connection_id', 'status'],
                'conversion_connection_versions_workspace_status_index',
            );
        });

        Schema::table('conversion_source_connection_versions', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'connection_id'],
                'conversion_connection_versions_workspace_connection_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('conversion_source_connections')
                ->restrictOnDelete();
        });

        Schema::table('conversion_source_connections', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'id', 'current_version_id'],
                'conversion_connections_current_version_fk',
            )
                ->references(['workspace_id', 'connection_id', 'id'])
                ->on('conversion_source_connection_versions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversion_source_connections', function (Blueprint $table): void {
            $table->dropForeign('conversion_connections_current_version_fk');
        });
        Schema::table('conversion_source_connection_versions', function (Blueprint $table): void {
            $table->dropForeign('conversion_connection_versions_workspace_connection_fk');
        });
        Schema::dropIfExists('conversion_source_connection_versions');
        Schema::dropIfExists('conversion_source_connections');
    }
};
