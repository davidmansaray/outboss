<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_budget_envelopes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->string('kind', 64);
            $table->string('owner_key', 160);
            $table->unsignedBigInteger('hard_limit_micros');
            $table->string('currency', 3)->default('USD');
            $table->unsignedInteger('pricing_version')->default(1);
            $table->string('catalogue_version', 64)->nullable();
            $table->unsignedBigInteger('held_micros')->default(0);
            $table->unsignedBigInteger('committed_micros')->default(0);
            $table->string('state', 24)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'kind', 'owner_key'], 'ai_budget_envelopes_owner_unique');
            $table->unique(['workspace_id', 'id'], 'ai_budget_envelopes_workspace_id_unique');
            $table->index(['workspace_id', 'state', 'updated_at']);
        });

        Schema::table('ai_budget_runs', function (Blueprint $table): void {
            $table->uuid('budget_envelope_id')->nullable()->after('task');
            $table->foreign('budget_envelope_id')->references('id')->on('ai_budget_envelopes')->restrictOnDelete();
            $table->index(['workspace_id', 'budget_envelope_id', 'status'], 'ai_budget_runs_envelope_status_index');
        });

        Schema::table('ai_provider_operations', function (Blueprint $table): void {
            $table->uuid('budget_envelope_id')->nullable()->after('budget_run_id');
            $table->foreign('budget_envelope_id')->references('id')->on('ai_budget_envelopes')->restrictOnDelete();
            $table->string('provider_response_id')->nullable()->after('provider_generation_id');
            $table->json('provider_metadata')->nullable()->after('provider_response_id');
            $table->unsignedBigInteger('fencing_token')->default(0)->after('provider_metadata');
            $table->index(['workspace_id', 'budget_envelope_id', 'status'], 'ai_ops_envelope_status_index');
        });

        Schema::table('ai_provider_call_attempts', function (Blueprint $table): void {
            $table->json('safe_metadata')->nullable()->after('safe_error_code');
            $table->unsignedBigInteger('fencing_token')->nullable()->after('safe_metadata');
        });

        Schema::table('ai_usage_ledger', function (Blueprint $table): void {
            $table->uuid('budget_envelope_id')->nullable()->after('budget_run_id');
            $table->foreign('budget_envelope_id')->references('id')->on('ai_budget_envelopes')->restrictOnDelete();
            $table->string('cost_evidence_source', 32)->nullable()->after('cost_basis');
            $table->index(['budget_envelope_id', 'occurred_at'], 'ai_usage_envelope_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_ledger', function (Blueprint $table): void {
            $table->dropForeign(['budget_envelope_id']);
            $table->dropIndex('ai_usage_envelope_occurred_index');
            $table->dropColumn(['budget_envelope_id', 'cost_evidence_source']);
        });
        Schema::table('ai_provider_call_attempts', function (Blueprint $table): void {
            $table->dropColumn(['safe_metadata', 'fencing_token']);
        });
        Schema::table('ai_provider_operations', function (Blueprint $table): void {
            $table->dropForeign(['budget_envelope_id']);
            $table->dropIndex('ai_ops_envelope_status_index');
            $table->dropColumn(['budget_envelope_id', 'provider_response_id', 'provider_metadata', 'fencing_token']);
        });
        Schema::table('ai_budget_runs', function (Blueprint $table): void {
            $table->dropForeign(['budget_envelope_id']);
            $table->dropIndex('ai_budget_runs_envelope_status_index');
            $table->dropColumn('budget_envelope_id');
        });
        Schema::dropIfExists('ai_budget_envelopes');
    }
};
