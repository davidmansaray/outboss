<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_client_operations', function (Blueprint $table): void {
            $table->id();
            // Preserve the operation/result audit after account deletion
            // without retaining a foreign key that blocks the deletion.
            $table->foreignId('initiating_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_operation_id', 128);
            $table->char('server_fingerprint', 64);
            $table->foreignId('resulting_workspace_id')->nullable()->constrained('workspaces')->restrictOnDelete();
            $table->string('redirect_route', 160)->nullable();
            $table->json('redirect_parameters')->nullable();
            $table->json('response_metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['initiating_user_id', 'client_operation_id'],
                'workspace_client_ops_user_operation_unique'
            );
            $table->index('resulting_workspace_id', 'workspace_client_ops_resulting_workspace_index');
        });

        Schema::create('gtm_profile_client_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('gtm_profile_id')->constrained('gtm_profiles')->restrictOnDelete();
            $table->string('client_operation_id', 128);
            $table->char('server_fingerprint', 64);
            $table->unsignedInteger('expected_lock_version')->nullable();
            $table->unsignedInteger('resulting_lock_version')->nullable();
            $table->uuid('expected_version_id')->nullable();
            $table->uuid('resulting_version_id')->nullable();
            $table->json('response_metadata')->nullable();
            $table->timestamps();

            $table->foreign(['gtm_profile_id', 'expected_version_id'], 'gtm_profile_ops_expected_version_fk')
                ->references(['gtm_profile_id', 'id'])
                ->on('gtm_profile_versions')
                ->restrictOnDelete();
            $table->foreign(['gtm_profile_id', 'resulting_version_id'], 'gtm_profile_ops_resulting_version_fk')
                ->references(['gtm_profile_id', 'id'])
                ->on('gtm_profile_versions')
                ->restrictOnDelete();
            $table->unique(
                ['gtm_profile_id', 'client_operation_id'],
                'gtm_profile_client_ops_aggregate_operation_unique'
            );
            $table->index(['gtm_profile_id', 'created_at'], 'gtm_profile_client_ops_created_index');
        });

        Schema::create('offer_client_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('offer_id')->constrained('offers')->restrictOnDelete();
            $table->string('client_operation_id', 128);
            $table->char('server_fingerprint', 64);
            $table->unsignedInteger('expected_lock_version')->nullable();
            $table->unsignedInteger('resulting_lock_version')->nullable();
            $table->uuid('expected_version_id')->nullable();
            $table->uuid('resulting_version_id')->nullable();
            $table->json('response_metadata')->nullable();
            $table->timestamps();

            $table->foreign(['offer_id', 'expected_version_id'], 'offer_ops_expected_version_fk')
                ->references(['offer_id', 'id'])
                ->on('offer_versions')
                ->restrictOnDelete();
            $table->foreign(['offer_id', 'resulting_version_id'], 'offer_ops_resulting_version_fk')
                ->references(['offer_id', 'id'])
                ->on('offer_versions')
                ->restrictOnDelete();
            $table->unique(
                ['offer_id', 'client_operation_id'],
                'offer_client_ops_aggregate_operation_unique'
            );
            $table->index(['offer_id', 'created_at'], 'offer_client_ops_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_client_operations');
        Schema::dropIfExists('gtm_profile_client_operations');
        Schema::dropIfExists('workspace_client_operations');
    }
};
