<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_versions', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->nullable()->after('id');
            $table->unsignedInteger('schema_version')->default(1)->after('version');
        });

        $workspaces = DB::table('offers')->pluck('workspace_id', 'id');
        foreach (DB::table('offer_versions')->select(['id', 'offer_id'])->get() as $version) {
            DB::table('offer_versions')->where('id', $version->id)->update([
                'workspace_id' => $workspaces[$version->offer_id] ?? null,
            ]);
        }

        Schema::table('offer_versions', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->nullable(false)->change();
            $table->foreign('workspace_id', 'offer_versions_workspace_fk')
                ->references('id')->on('workspaces')->restrictOnDelete();
            $table->unique(['workspace_id', 'id'], 'offer_versions_workspace_id_unique');
            $table->unique(['workspace_id', 'offer_id', 'id'], 'offer_versions_workspace_offer_id_unique');
        });

        Schema::table('offers', function (Blueprint $table): void {
            $table->string('availability_state', 24)->default('incomplete')->after('status');
            $table->uuid('current_readiness_evaluation_id')->nullable()->after('current_version_id');
            $table->unique(['workspace_id', 'id'], 'offers_workspace_id_unique');
            $table->unique(
                ['workspace_id', 'id', 'current_version_id'],
                'offers_workspace_current_version_unique',
            );
            $table->foreign(
                ['workspace_id', 'id', 'current_version_id'],
                'offers_workspace_current_version_fk',
            )->references(['workspace_id', 'offer_id', 'id'])
                ->on('offer_versions')->restrictOnDelete();
        });

        Schema::create('assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('current_version_id')->nullable();
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'assets_workspace_id_unique');
            $table->unique(['workspace_id', 'id', 'current_version_id'], 'assets_workspace_current_version_unique');
            $table->index(['workspace_id', 'status'], 'assets_workspace_status_index');
        });

        Schema::create('asset_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('asset_id');
            $table->unsignedInteger('version');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('external_url', 2048);
            $table->string('media_type', 120);
            $table->string('source', 160)->nullable();
            $table->char('content_hash', 64);
            $table->json('access_instructions');
            $table->string('approval_state', 24)->default('draft');
            $table->json('intended_audience');
            $table->timestamp('expires_at')->nullable();
            $table->string('availability_state', 24)->default('available');
            $table->string('licence', 160)->nullable();
            $table->string('classification', 32)->default('public');
            $table->json('delivery_restrictions');
            $table->json('tracking_metadata');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_display_name', 160)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['workspace_id', 'id'], 'asset_versions_workspace_id_unique');
            $table->unique(['workspace_id', 'asset_id', 'id'], 'asset_versions_workspace_asset_id_unique');
            $table->unique(['workspace_id', 'asset_id', 'version'], 'asset_versions_workspace_asset_version_unique');
            $table->foreign(['workspace_id', 'asset_id'], 'asset_versions_workspace_asset_fk')
                ->references(['workspace_id', 'id'])->on('assets')->restrictOnDelete();
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'id', 'current_version_id'],
                'assets_workspace_current_version_fk',
            )->references(['workspace_id', 'asset_id', 'id'])
                ->on('asset_versions')->restrictOnDelete();
        });

        Schema::create('fulfilment_capabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('display_name', 160);
            $table->string('mode', 32);
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('configuration_version')->default(1);
            $table->json('configuration');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('response_sla_hours')->nullable();
            $table->unsignedInteger('capacity_limit')->nullable();
            $table->string('capacity_window', 24)->nullable();
            $table->string('health_state', 24)->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->string('safe_blocker_code', 80)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'fulfilment_capabilities_workspace_id_unique');
            $table->index(['workspace_id', 'mode', 'status'], 'fulfilment_capabilities_workspace_status_index');
        });

        Schema::create('offer_version_review_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('offer_id');
            $table->uuid('offer_version_id');
            $table->string('action', 24);
            $table->string('from_state', 24)->nullable();
            $table->string('to_state', 24);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_display_name', 160)->nullable();
            $table->string('reason', 1000)->nullable();
            $table->char('policy_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['workspace_id', 'id'], 'offer_reviews_workspace_id_unique');
            $table->index(['workspace_id', 'offer_id', 'offer_version_id', 'created_at'], 'offer_reviews_workspace_version_index');
            $table->foreign(['workspace_id', 'offer_id', 'offer_version_id'], 'offer_reviews_workspace_version_fk')
                ->references(['workspace_id', 'offer_id', 'id'])->on('offer_versions')->restrictOnDelete();
        });

        Schema::create('offer_readiness_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('offer_id');
            $table->uuid('offer_version_id');
            $table->unsignedInteger('evaluator_version')->default(1);
            $table->string('state', 24);
            $table->json('blockers');
            $table->json('dependency_snapshot');
            $table->char('dependency_hash', 64);
            $table->string('trigger', 32);
            $table->timestamp('evaluated_at');
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['workspace_id', 'id'], 'offer_readiness_workspace_id_unique');
            $table->unique(['workspace_id', 'offer_id', 'id'], 'offer_readiness_workspace_offer_id_unique');
            $table->index(['workspace_id', 'offer_id', 'offer_version_id', 'evaluated_at'], 'offer_readiness_workspace_version_index');
            $table->foreign(['workspace_id', 'offer_id', 'offer_version_id'], 'offer_readiness_workspace_version_fk')
                ->references(['workspace_id', 'offer_id', 'id'])->on('offer_versions')->restrictOnDelete();
        });

        Schema::table('offers', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'id', 'current_readiness_evaluation_id'],
                'offers_workspace_readiness_fk',
            )->references(['workspace_id', 'offer_id', 'id'])
                ->on('offer_readiness_evaluations')->restrictOnDelete();
        });

        Schema::create('offer_version_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('offer_id');
            $table->uuid('offer_version_id');
            $table->uuid('asset_id');
            $table->uuid('asset_version_id');
            $table->string('role', 32);
            $table->json('delivery_rule');
            $table->timestamps();

            $table->unique(['workspace_id', 'offer_version_id', 'asset_version_id', 'role'], 'offer_version_assets_unique');
            $table->foreign(['workspace_id', 'offer_id', 'offer_version_id'], 'offer_version_assets_offer_fk')
                ->references(['workspace_id', 'offer_id', 'id'])->on('offer_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'asset_id', 'asset_version_id'], 'offer_version_assets_asset_fk')
                ->references(['workspace_id', 'asset_id', 'id'])->on('asset_versions')->restrictOnDelete();
        });

        Schema::create('fulfilment_capacity_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('fulfilment_capability_id');
            $table->string('window_key', 80);
            $table->unsignedInteger('limit_units');
            $table->unsignedInteger('reserved_units')->default(0);
            $table->unsignedInteger('consumed_units')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'fulfilment_capability_id', 'window_key'], 'fulfilment_capacity_windows_unique');
            $table->unique(['workspace_id', 'id'], 'fulfilment_capacity_windows_workspace_id_unique');
            $table->foreign(['workspace_id', 'fulfilment_capability_id'], 'fulfilment_capacity_windows_capability_fk')
                ->references(['workspace_id', 'id'])->on('fulfilment_capabilities')->restrictOnDelete();
        });

        Schema::create('fulfilment_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('offer_id');
            $table->uuid('offer_version_id');
            $table->uuid('conversion_event_id');
            $table->unsignedBigInteger('account_id');
            $table->uuid('fulfilment_capability_id');
            $table->string('idempotency_key', 160);
            $table->json('required_inputs');
            $table->timestamp('due_at')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state', 24)->default('pending');
            $table->json('result_references')->nullable();
            $table->string('safe_failure_code', 80)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'fulfilment_requests_workspace_id_unique');
            $table->unique(['workspace_id', 'idempotency_key'], 'fulfilment_requests_workspace_idempotency_unique');
            $table->index(['workspace_id', 'state', 'due_at'], 'fulfilment_requests_workspace_state_due_index');
            $table->foreign(['workspace_id', 'offer_id', 'offer_version_id'], 'fulfilment_requests_offer_fk')
                ->references(['workspace_id', 'offer_id', 'id'])->on('offer_versions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'conversion_event_id'], 'fulfilment_requests_event_fk')
                ->references(['workspace_id', 'id'])->on('conversion_events')->restrictOnDelete();
            $table->foreign(['workspace_id', 'account_id'], 'fulfilment_requests_account_fk')
                ->references(['workspace_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['workspace_id', 'fulfilment_capability_id'], 'fulfilment_requests_capability_fk')
                ->references(['workspace_id', 'id'])->on('fulfilment_capabilities')->restrictOnDelete();
        });

        Schema::create('fulfilment_capacity_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('fulfilment_request_id');
            $table->uuid('fulfilment_capability_id');
            $table->unsignedBigInteger('capacity_window_id');
            $table->unsignedInteger('units')->default(1);
            $table->string('state', 24)->default('active');
            $table->timestamp('reserved_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'fulfilment_reservations_workspace_id_unique');
            $table->unique(['workspace_id', 'fulfilment_request_id'], 'fulfilment_reservations_request_unique');
            $table->foreign(['workspace_id', 'fulfilment_request_id'], 'fulfilment_reservations_request_fk')
                ->references(['workspace_id', 'id'])->on('fulfilment_requests')->restrictOnDelete();
            $table->foreign(['workspace_id', 'fulfilment_capability_id'], 'fulfilment_reservations_capability_fk')
                ->references(['workspace_id', 'id'])->on('fulfilment_capabilities')->restrictOnDelete();
            $table->foreign(['workspace_id', 'capacity_window_id'], 'fulfilment_reservations_window_fk')
                ->references(['workspace_id', 'id'])->on('fulfilment_capacity_windows')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfilment_capacity_reservations');
        Schema::dropIfExists('fulfilment_requests');
        Schema::dropIfExists('fulfilment_capacity_windows');
        Schema::dropIfExists('offer_version_assets');

        Schema::table('offers', function (Blueprint $table): void {
            $table->dropForeign('offers_workspace_readiness_fk');
            $table->dropForeign('offers_workspace_current_version_fk');
        });
        Schema::dropIfExists('offer_readiness_evaluations');
        Schema::dropIfExists('offer_version_review_events');

        Schema::table('assets', function (Blueprint $table): void {
            $table->dropForeign('assets_workspace_current_version_fk');
        });
        Schema::dropIfExists('asset_versions');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('fulfilment_capabilities');

        Schema::table('offers', function (Blueprint $table): void {
            $table->dropUnique('offers_workspace_current_version_unique');
            $table->dropUnique('offers_workspace_id_unique');
            $table->dropColumn(['availability_state', 'current_readiness_evaluation_id']);
        });

        Schema::table('offer_versions', function (Blueprint $table): void {
            $table->dropForeign('offer_versions_workspace_fk');
            $table->dropUnique('offer_versions_workspace_offer_id_unique');
            $table->dropUnique('offer_versions_workspace_id_unique');
            $table->dropColumn(['workspace_id', 'schema_version']);
        });
    }
};
