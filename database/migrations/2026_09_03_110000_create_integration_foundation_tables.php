<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->string('provider', 80);
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('active_credential_version_id')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_healthy_at')->nullable();
            $table->timestamp('retry_after_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'provider']);
            $table->unique(['workspace_id', 'id'], 'integration_connections_workspace_id_id_unique');
            $table->index(['workspace_id', 'status'], 'integration_connections_workspace_status_index');
        });

        Schema::create('integration_credential_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('connection_id')->constrained('integration_connections')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->longText('encrypted_secret')->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('tested_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retire_after')->nullable();
            $table->timestamp('wiped_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'version']);
            $table->unique(['workspace_id', 'id'], 'integration_credentials_workspace_id_id_unique');
            $table->foreign(
                ['workspace_id', 'connection_id'],
                'integration_credentials_workspace_connection_fk'
            )
                ->references(['workspace_id', 'id'])
                ->on('integration_connections')
                ->restrictOnDelete();
            $table->index(['workspace_id', 'connection_id', 'status'], 'integration_credentials_status_index');
        });

        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'active_credential_version_id'],
                'integration_connections_active_credential_fk'
            )
                ->references(['workspace_id', 'id'])
                ->on('integration_credential_versions')
                ->restrictOnDelete();
        });

        Schema::create('integration_provider_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('connection_id')->constrained('integration_connections')->restrictOnDelete();
            $table->string('provider', 80);
            $table->string('adapter_version', 80);
            $table->string('capability', 120);
            $table->unsignedInteger('capability_schema_version')->default(1);
            $table->unsignedInteger('pricing_version')->default(1);
            $table->unsignedInteger('policy_version')->default(1);
            $table->string('code_definition_hash', 64);
            $table->json('coverage')->nullable();
            $table->json('limits')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'integration_registrations_workspace_id_id_unique');
            $table->unique(
                ['connection_id', 'capability', 'adapter_version'],
                'integration_registrations_connection_capability_version_unique'
            );
            $table->foreign(
                ['workspace_id', 'connection_id'],
                'integration_registrations_workspace_connection_fk'
            )
                ->references(['workspace_id', 'id'])
                ->on('integration_connections')
                ->restrictOnDelete();
            $table->index(['workspace_id', 'capability', 'enabled'], 'integration_registrations_capability_index');
        });

        Schema::create('integration_capability_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->string('capability', 120);
            $table->foreignId('active_registration_id')
                ->nullable()
                ->constrained('integration_provider_registrations')
                ->nullOnDelete();
            $table->string('status', 24)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'capability']);
            $table->foreign(
                ['workspace_id', 'active_registration_id'],
                'integration_routes_workspace_registration_fk'
            )
                ->references(['workspace_id', 'id'])
                ->on('integration_provider_registrations')
                ->restrictOnDelete();
            $table->index(['workspace_id', 'status'], 'integration_routes_workspace_status_index');
        });

        Schema::create('integration_budget_settings', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->primary()->constrained()->restrictOnDelete();
            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('monthly_limit_micros')->default(50_000_000);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('integration_budget_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->date('period_start_utc');
            $table->date('period_end_utc');
            $table->unsignedBigInteger('limit_snapshot_micros');
            $table->unsignedBigInteger('held_micros')->default(0);
            $table->unsignedBigInteger('committed_micros')->default(0);
            $table->boolean('over_limit')->default(false);
            $table->boolean('budget_breach')->default(false);
            $table->unsignedInteger('settings_version')->default(1);
            $table->timestamps();

            $table->unique(['workspace_id', 'period_start_utc']);
            $table->unique(['workspace_id', 'id'], 'integration_budget_periods_workspace_id_unique');
            $table->index(['workspace_id', 'period_end_utc'], 'integration_budget_periods_end_index');
        });

        Schema::create('external_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_period_id')->nullable()->constrained('integration_budget_periods')->restrictOnDelete();
            $table->uuid('source_run_id')->nullable();
            $table->foreignId('registration_id')->constrained('integration_provider_registrations')->restrictOnDelete();
            $table->foreignId('connection_id')->constrained('integration_connections')->restrictOnDelete();
            $table->foreignId('credential_version_id')->constrained('integration_credential_versions')->restrictOnDelete();
            $table->string('provider', 80);
            $table->string('capability', 120);
            $table->string('operation', 80);
            $table->string('idempotency_key', 160);
            $table->string('request_fingerprint', 64);
            $table->unsignedInteger('capability_schema_version')->default(1);
            $table->unsignedInteger('pricing_version')->default(1);
            $table->unsignedInteger('policy_version')->default(1);
            $table->string('endpoint', 160);
            $table->string('status', 32)->default('planned');
            $table->string('native_unit', 32)->nullable();
            $table->unsignedBigInteger('reserved_native_units')->default(0);
            $table->unsignedBigInteger('reserved_cost_micros')->default(0);
            $table->unsignedBigInteger('actual_native_units')->nullable();
            $table->unsignedBigInteger('actual_cost_micros')->nullable();
            $table->string('cost_basis', 48)->nullable();
            $table->string('provider_request_id', 160)->nullable();
            $table->string('provider_snapshot_id', 160)->nullable();
            $table->string('safe_request_id', 160)->nullable();
            $table->json('safe_metadata')->nullable();
            $table->json('result_reference')->nullable();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('lease_owner', 120)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('retry_after_at')->nullable();
            $table->string('safe_error_code', 80)->nullable();
            $table->string('safe_error_message', 500)->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('reconcile_after')->nullable();
            $table->timestamp('finalize_after')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key']);
            $table->unique(['workspace_id', 'id'], 'external_operations_workspace_id_unique');
            $table->unique(
                ['workspace_id', 'registration_id', 'id'],
                'external_operations_workspace_registration_id_unique'
            );
            $table->foreign(
                ['workspace_id', 'registration_id'],
                'external_operations_workspace_registration_fk'
            )
                ->references(['workspace_id', 'id'])
                ->on('integration_provider_registrations')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'connection_id'],
                'external_operations_workspace_connection_fk'
            )
                ->references(['workspace_id', 'id'])
                ->on('integration_connections')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'credential_version_id'],
                'external_operations_workspace_credential_fk'
            )
                ->references(['workspace_id', 'id'])
                ->on('integration_credential_versions')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'budget_period_id'],
                'external_operations_workspace_budget_period_fk'
            )
                ->references(['workspace_id', 'id'])
                ->on('integration_budget_periods')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'source_run_id'],
                'external_operations_workspace_source_run_fk'
            )
                ->references(['workspace_id', 'id'])
                ->on('source_runs')
                ->restrictOnDelete();
            $table->index(['workspace_id', 'status', 'finalize_after'], 'external_operations_workspace_status_finalize_index');
            $table->index(['workspace_id', 'source_run_id'], 'external_operations_source_run_index');
            $table->index(['workspace_id', 'provider', 'capability'], 'external_operations_provider_capability_index');
            $table->index('provider_request_id');
            $table->index('provider_snapshot_id');
        });

        Schema::create('external_operation_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id');
            $table->unsignedInteger('attempt_number');
            $table->string('kind', 24);
            $table->string('status', 32);
            $table->string('provider_request_id', 160)->nullable();
            $table->string('provider_snapshot_id', 160)->nullable();
            $table->string('safe_error_code', 80)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('retry_after_at')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->foreign('operation_id')->references('id')->on('external_operations')->restrictOnDelete();
            $table->unique(['operation_id', 'attempt_number']);
        });

        Schema::create('external_usage_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_id');
            $table->foreignId('budget_period_id')->nullable()->constrained('integration_budget_periods')->restrictOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained('integration_provider_registrations')->nullOnDelete();
            $table->string('provider', 80);
            $table->string('capability', 120);
            $table->string('operation', 80);
            $table->string('endpoint', 160);
            $table->string('entry_type', 24);
            $table->string('native_unit', 32);
            $table->unsignedBigInteger('native_units')->nullable();
            $table->string('raw_provider_cost', 120)->nullable();
            $table->bigInteger('cost_micros');
            $table->string('cost_basis', 48);
            $table->string('provider_request_id', 160)->nullable();
            $table->string('provider_snapshot_id', 160)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('operation_id')->references('id')->on('external_operations')->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'operation_id'],
                'external_usage_workspace_operation_fk'
            )
                ->references(['workspace_id', 'id'])
                ->on('external_operations')
                ->restrictOnDelete();
            $table->index(['workspace_id', 'occurred_at'], 'external_usage_workspace_time_index');
            $table->index(['workspace_id', 'provider', 'occurred_at'], 'external_usage_provider_time_index');
            $table->index(['operation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';
        try {
            if ($sqlite) {
                // SQLite cannot alter a table to drop one named foreign key. The
                // integration tables are additive and are dropped only as a
                // structural local rollback, so temporarily disable FK checks.
                Schema::disableForeignKeyConstraints();
            }
            Schema::dropIfExists('external_usage_entries');
            Schema::dropIfExists('external_operation_attempts');
            Schema::dropIfExists('external_operations');
            Schema::dropIfExists('integration_budget_periods');
            Schema::dropIfExists('integration_budget_settings');
            Schema::dropIfExists('integration_capability_routes');
            Schema::dropIfExists('integration_provider_registrations');
            if (! $sqlite) {
                Schema::table('integration_connections', function (Blueprint $table): void {
                    $table->dropForeign('integration_connections_active_credential_fk');
                });
            }
            Schema::dropIfExists('integration_credential_versions');
            Schema::dropIfExists('integration_connections');
        } finally {
            if ($sqlite) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }
};
