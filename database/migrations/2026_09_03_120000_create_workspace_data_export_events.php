<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_data_export_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('prospect_source_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('export_type', 80);
            $table->string('format', 16)->default('csv');
            $table->char('policy_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(
                ['workspace_id', 'prospect_source_id'],
                'workspace_exports_workspace_source_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('prospect_sources')
                ->restrictOnDelete();
            $table->index(['workspace_id', 'created_at'], 'workspace_exports_workspace_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_data_export_events');
    }
};
