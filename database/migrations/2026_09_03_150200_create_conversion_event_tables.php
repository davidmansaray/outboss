<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_event_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('connection_id')->nullable();
            $table->uuid('connection_version_id')->nullable();
            $table->foreignId('manual_actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_namespace', 191);
            $table->string('source_type', 32);
            $table->string('external_event_id', 255)->nullable();
            $table->string('dedupe_key', 255)->nullable();
            $table->char('dedupe_hash', 64);
            $table->char('payload_hash', 64);
            $table->unsignedInteger('schema_version')->default(1);
            $table->timestamp('received_at');
            $table->timestamp('occurred_at')->nullable();
            $table->string('verification_state', 24);
            $table->string('intake_state', 24);
            $table->string('safe_rejection_code', 80)->nullable();
            $table->longText('unmatched_identity_envelope')->nullable();
            $table->timestamp('identity_expires_at')->nullable();
            $table->timestamp('identity_redacted_at')->nullable();
            $table->uuid('conversion_event_id')->nullable();
            $table->string('actor_display_name', 160)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'conversion_receipts_workspace_id_unique');
            $table->unique(
                ['workspace_id', 'source_namespace', 'dedupe_hash'],
                'conversion_receipts_workspace_namespace_dedupe_unique',
            );
            $table->index(['workspace_id', 'source_type', 'received_at'], 'conversion_receipts_workspace_source_received_index');
            $table->index(['identity_expires_at', 'identity_redacted_at'], 'conversion_receipts_identity_expiry_index');
        });

        Schema::create('conversion_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('conversion_definition_id');
            $table->uuid('conversion_definition_version_id');
            $table->uuid('receipt_id');
            $table->string('source_namespace', 191);
            $table->string('source_type', 32);
            $table->string('external_event_id', 255)->nullable();
            $table->json('properties');
            $table->json('identity_handles');
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->decimal('value', 20, 6)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('verification_state', 24);
            $table->string('state', 24)->default('received');
            $table->unsignedBigInteger('matched_account_id')->nullable();
            $table->uuid('lineage_root_id')->nullable();
            $table->uuid('corrects_event_id')->nullable();
            $table->uuid('reverses_event_id')->nullable();
            $table->string('safe_rejection_code', 80)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'conversion_events_workspace_id_unique');
            $table->index(['workspace_id', 'state', 'occurred_at'], 'conversion_events_workspace_state_occurred_index');
            $table->index(['workspace_id', 'matched_account_id'], 'conversion_events_workspace_account_index');
            $table->index(['workspace_id', 'lineage_root_id'], 'conversion_events_workspace_lineage_index');
        });

        Schema::create('conversion_event_matches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('conversion_event_id');
            $table->unsignedInteger('attempt_number');
            $table->json('supplied_identity_hashes');
            $table->json('identity_handles');
            $table->string('resolution_path', 64);
            $table->unsignedInteger('candidate_count')->default(0);
            $table->string('result', 24);
            $table->unsignedBigInteger('matched_account_id')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_display_name', 160)->nullable();
            $table->string('reason', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['workspace_id', 'id'], 'conversion_matches_workspace_id_unique');
            $table->unique(
                ['workspace_id', 'conversion_event_id', 'attempt_number'],
                'conversion_matches_workspace_event_attempt_unique',
            );
            $table->index(['workspace_id', 'result', 'created_at'], 'conversion_matches_workspace_result_index');
        });

        Schema::create('conversion_event_review_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->uuid('conversion_event_id');
            $table->string('action', 24);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_display_name', 160)->nullable();
            $table->string('reason', 1000)->nullable();
            $table->string('prior_state', 24)->nullable();
            $table->string('projected_state', 24)->nullable();
            $table->uuid('successor_event_id')->nullable();
            $table->uuid('successor_match_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['workspace_id', 'id'], 'conversion_reviews_workspace_id_unique');
            $table->index(['workspace_id', 'conversion_event_id', 'created_at'], 'conversion_reviews_workspace_event_created_index');
        });

        Schema::table('conversion_event_receipts', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'connection_id'],
                'conversion_receipts_workspace_connection_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('conversion_source_connections')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'connection_id', 'connection_version_id'],
                'conversion_receipts_workspace_connection_version_fk',
            )
                ->references(['workspace_id', 'connection_id', 'id'])
                ->on('conversion_source_connection_versions')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'conversion_event_id'],
                'conversion_receipts_workspace_event_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('conversion_events')
                ->restrictOnDelete();
        });

        Schema::table('conversion_events', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'conversion_definition_id', 'conversion_definition_version_id'],
                'conversion_events_workspace_definition_version_fk',
            )
                ->references(['workspace_id', 'conversion_definition_id', 'id'])
                ->on('conversion_definition_versions')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'receipt_id'],
                'conversion_events_workspace_receipt_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('conversion_event_receipts')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'matched_account_id'],
                'conversion_events_workspace_account_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('accounts')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'lineage_root_id'],
                'conversion_events_workspace_lineage_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('conversion_events')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'corrects_event_id'],
                'conversion_events_workspace_corrects_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('conversion_events')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'reverses_event_id'],
                'conversion_events_workspace_reverses_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('conversion_events')
                ->restrictOnDelete();
        });

        Schema::table('conversion_event_matches', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'conversion_event_id'],
                'conversion_matches_workspace_event_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('conversion_events')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'matched_account_id'],
                'conversion_matches_workspace_account_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('accounts')
                ->restrictOnDelete();
        });

        Schema::table('conversion_event_review_events', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'conversion_event_id'],
                'conversion_reviews_workspace_event_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('conversion_events')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'successor_event_id'],
                'conversion_reviews_workspace_successor_event_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('conversion_events')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'successor_match_id'],
                'conversion_reviews_workspace_successor_match_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('conversion_event_matches')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversion_event_review_events', function (Blueprint $table): void {
            $table->dropForeign('conversion_reviews_workspace_successor_match_fk');
            $table->dropForeign('conversion_reviews_workspace_successor_event_fk');
            $table->dropForeign('conversion_reviews_workspace_event_fk');
        });
        Schema::table('conversion_event_matches', function (Blueprint $table): void {
            $table->dropForeign('conversion_matches_workspace_account_fk');
            $table->dropForeign('conversion_matches_workspace_event_fk');
        });
        Schema::table('conversion_events', function (Blueprint $table): void {
            $table->dropForeign('conversion_events_workspace_reverses_fk');
            $table->dropForeign('conversion_events_workspace_corrects_fk');
            $table->dropForeign('conversion_events_workspace_lineage_fk');
            $table->dropForeign('conversion_events_workspace_account_fk');
            $table->dropForeign('conversion_events_workspace_receipt_fk');
            $table->dropForeign('conversion_events_workspace_definition_version_fk');
        });
        Schema::table('conversion_event_receipts', function (Blueprint $table): void {
            $table->dropForeign('conversion_receipts_workspace_event_fk');
            $table->dropForeign('conversion_receipts_workspace_connection_version_fk');
            $table->dropForeign('conversion_receipts_workspace_connection_fk');
        });

        Schema::dropIfExists('conversion_event_review_events');
        Schema::dropIfExists('conversion_event_matches');
        Schema::dropIfExists('conversion_events');
        Schema::dropIfExists('conversion_event_receipts');
    }
};
