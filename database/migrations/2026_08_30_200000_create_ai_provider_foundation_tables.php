<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->string('capability', 32);
            $table->string('provider', 32);
            $table->string('status', 32)->default('misconfigured');
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_healthy_at')->nullable();
            $table->timestamp('retry_after_at')->nullable();
            $table->string('provider_balance_summary', 120)->nullable();
            $table->timestamp('balance_checked_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'capability']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('ai_provider_credential_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credential_id')->constrained('ai_provider_credentials')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->longText('encrypted_secret')->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('tested_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retire_after')->nullable();
            $table->timestamp('wiped_at')->nullable();
            $table->timestamps();

            $table->unique(['credential_id', 'version']);
            $table->index(['credential_id', 'status']);
        });

        Schema::table('ai_provider_credentials', function (Blueprint $table): void {
            $table->foreign('active_version_id')->references('id')->on('ai_provider_credential_versions')->nullOnDelete();
        });

        Schema::create('ai_provider_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('credential_id')->nullable()->constrained('ai_provider_credentials')->nullOnDelete();
            $table->foreignId('credential_version_id')->nullable()->constrained('ai_provider_credential_versions')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 24)->default('user');
            $table->string('event', 40);
            $table->string('from_state')->nullable();
            $table->string('to_state')->nullable();
            $table->json('safe_metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('ai_budget_settings', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->primary()->constrained()->restrictOnDelete();
            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('monthly_limit_micros')->default(50_000_000);
            $table->unsignedBigInteger('tavily_credit_price_micros')->default(8_000);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_task_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->string('task', 40);
            $table->string('model_id', 120);
            $table->unsignedBigInteger('per_run_limit_micros');
            $table->unsignedInteger('policy_version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'task']);
        });

        Schema::create('ai_budget_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->date('period_start_utc');
            $table->date('period_end_utc');
            $table->unsignedBigInteger('limit_snapshot_micros');
            $table->unsignedBigInteger('held_micros')->default(0);
            $table->unsignedBigInteger('committed_micros')->default(0);
            $table->boolean('over_limit')->default(false);
            $table->boolean('budget_breach')->default(false);
            $table->unsignedInteger('settings_version');
            $table->timestamps();

            $table->unique(['workspace_id', 'period_start_utc']);
        });

        Schema::create('ai_budget_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->string('task', 40);
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('limit_snapshot_micros');
            $table->unsignedBigInteger('held_micros')->default(0);
            $table->unsignedBigInteger('committed_micros')->default(0);
            $table->string('status', 24)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'opened_at']);
        });

        Schema::create('ai_provider_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_period_id')->nullable()->constrained('ai_budget_periods')->restrictOnDelete();
            $table->uuid('budget_run_id')->nullable();
            $table->foreign('budget_run_id')->references('id')->on('ai_budget_runs')->restrictOnDelete();
            $table->foreignId('credential_id')->constrained('ai_provider_credentials')->restrictOnDelete();
            $table->foreignId('credential_version_id')->constrained('ai_provider_credential_versions')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('capability', 32);
            $table->string('task', 40);
            $table->string('operation', 40);
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 160);
            $table->string('request_fingerprint', 64);
            $table->string('model_or_endpoint', 160);
            $table->unsignedInteger('policy_version');
            $table->unsignedInteger('pricing_version');
            $table->string('status', 32)->default('planned');
            $table->unsignedBigInteger('reserved_native_units')->default(0);
            $table->unsignedBigInteger('reserved_cost_micros')->default(0);
            $table->unsignedBigInteger('actual_native_units')->nullable();
            $table->unsignedBigInteger('actual_cost_micros')->nullable();
            $table->string('cost_basis', 40)->nullable();
            $table->string('provider_request_id')->nullable();
            $table->string('provider_generation_id')->nullable();
            $table->string('safe_request_id')->nullable();
            $table->string('lease_owner')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('retry_after_at')->nullable();
            $table->string('safe_error_code')->nullable();
            $table->string('safe_error_message', 500)->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('reconcile_after')->nullable();
            $table->timestamp('finalize_after')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['workspace_id', 'status', 'finalize_after'], 'ai_ops_workspace_status_finalize_index');
            $table->index('provider_request_id');
            $table->index('provider_generation_id');
            $table->index(['budget_run_id', 'created_at']);
            $table->index(['budget_period_id', 'created_at']);
        });

        Schema::create('ai_provider_call_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id');
            $table->foreign('operation_id')->references('id')->on('ai_provider_operations')->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('kind', 24);
            $table->string('status', 32);
            $table->string('provider_request_id')->nullable();
            $table->string('provider_generation_id')->nullable();
            $table->string('safe_error_code')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('retry_after_at')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->unique(['operation_id', 'attempt_number']);
        });

        Schema::create('ai_usage_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_id');
            $table->foreign('operation_id')->references('id')->on('ai_provider_operations')->restrictOnDelete();
            $table->foreignId('budget_period_id')->constrained('ai_budget_periods')->restrictOnDelete();
            $table->uuid('budget_run_id');
            $table->foreign('budget_run_id')->references('id')->on('ai_budget_runs')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('operation', 40);
            $table->string('task', 40);
            $table->string('model_or_endpoint', 160);
            $table->string('entry_type', 24);
            $table->string('native_unit', 24);
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('reasoning_tokens')->nullable();
            $table->unsignedBigInteger('cache_tokens')->nullable();
            $table->unsignedInteger('tavily_credits')->nullable();
            $table->string('raw_provider_cost')->nullable();
            $table->bigInteger('cost_micros');
            $table->string('cost_basis', 40);
            $table->string('provider_request_id')->nullable();
            $table->string('provider_generation_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'occurred_at']);
            $table->index(['budget_run_id', 'occurred_at']);
            $table->index(['provider', 'occurred_at']);
            $table->index(['operation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_ledger');
        Schema::dropIfExists('ai_provider_call_attempts');
        Schema::dropIfExists('ai_provider_operations');
        Schema::dropIfExists('ai_budget_runs');
        Schema::dropIfExists('ai_budget_periods');
        Schema::dropIfExists('ai_task_settings');
        Schema::dropIfExists('ai_budget_settings');
        Schema::dropIfExists('ai_provider_events');
        Schema::table('ai_provider_credentials', function (Blueprint $table): void {
            $table->dropForeign(['active_version_id']);
        });
        Schema::dropIfExists('ai_provider_credential_versions');
        Schema::dropIfExists('ai_provider_credentials');
    }
};
