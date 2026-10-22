<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_client_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('asset_id');
            $table->string('client_operation_id', 160);
            $table->char('server_fingerprint', 64);
            $table->unsignedInteger('expected_lock_version')->nullable();
            $table->unsignedInteger('resulting_lock_version')->nullable();
            $table->uuid('expected_version_id')->nullable();
            $table->uuid('resulting_version_id');
            $table->json('response_metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'asset_id', 'client_operation_id'],
                'asset_client_ops_aggregate_operation_unique',
            );
            $table->index(['workspace_id', 'asset_id', 'created_at'], 'asset_client_ops_created_index');
            $table->foreign(['workspace_id', 'asset_id'], 'asset_client_ops_asset_fk')
                ->references(['workspace_id', 'id'])->on('assets')->restrictOnDelete();
            $table->foreign(['workspace_id', 'asset_id', 'expected_version_id'], 'asset_client_ops_expected_version_fk')
                ->references(['workspace_id', 'asset_id', 'id'])->on('asset_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'asset_id', 'resulting_version_id'], 'asset_client_ops_resulting_version_fk')
                ->references(['workspace_id', 'asset_id', 'id'])->on('asset_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_client_operations');
    }
};
