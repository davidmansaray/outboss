<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_user_id');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->unsignedInteger('token_version')->default(0);
            $table->json('granted_scopes')->nullable();
            $table->text('provider_metadata')->nullable();
            $table->string('status');
            $table->string('last_error_code')->nullable();
            $table->string('last_error_message')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider', 'provider_user_id']);
        });

        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_connection_id')->constrained()->restrictOnDelete();
            $table->string('provider_mailbox_id');
            $table->string('email_address');
            $table->string('display_name')->nullable();
            $table->string('status');
            $table->json('provider_metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_error')->nullable();
            $table->timestamps();
            $table->unique(['provider_connection_id', 'provider_mailbox_id']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'email_address']);
        });

        Schema::create('oauth_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->char('state_hash', 64)->unique();
            $table->text('pkce_verifier')->nullable();
            $table->foreignId('reconnect_connection_id')->nullable()->constrained('provider_connections')->nullOnDelete();
            $table->foreignId('reconnect_mailbox_id')->nullable()->constrained('mailboxes')->nullOnDelete();
            $table->string('intent');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mailbox_sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained()->restrictOnDelete();
            $table->string('scope');
            $table->string('remote_folder_id')->nullable();
            $table->longText('cursor')->nullable();
            $table->unsignedBigInteger('high_water_timestamp')->nullable();
            $table->json('high_water_remote_ids')->nullable();
            $table->json('scan_state')->nullable();
            $table->string('status');
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->unique(['mailbox_id', 'scope']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status');
            $table->string('send_mode');
            $table->string('timezone');
            $table->time('send_window_start')->default('09:00:00');
            $table->time('send_window_end')->default('17:00:00');
            $table->boolean('business_days_only')->default(true);
            $table->unsignedBigInteger('draft_sequence_version_id')->nullable();
            $table->unsignedBigInteger('published_sequence_version_id')->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('sequence_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'version']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreign('draft_sequence_version_id')->references('id')->on('sequence_versions')->nullOnDelete();
            $table->foreign('published_sequence_version_id')->references('id')->on('sequence_versions')->nullOnDelete();
        });

        Schema::create('sequence_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_version_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('title');
            $table->unsignedInteger('delay_minutes');
            $table->string('send_as');
            $table->string('subject_template')->nullable();
            $table->text('body_template');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['sequence_version_id', 'position']);
        });

        Schema::create('campaign_mailbox', function (Blueprint $table) {
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('mailbox_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->primary(['campaign_id', 'mailbox_id']);
            $table->unique(['campaign_id', 'position']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('mailbox_id')->constrained()->restrictOnDelete();
            $table->foreignId('sequence_version_id')->constrained()->restrictOnDelete();
            $table->string('email_address');
            $table->string('email_normalized');
            $table->string('full_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('company')->nullable();
            $table->string('status');
            $table->foreignId('next_sequence_step_id')->nullable()->constrained('sequence_steps')->nullOnDelete();
            $table->timestamp('next_send_at')->nullable();
            $table->timestamp('first_sent_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->string('stop_reason')->nullable();
            $table->string('mailbox_assignment');
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->string('blocked_from_status')->nullable();
            $table->unsignedInteger('remaining_delay_seconds')->nullable();
            $table->uuid('send_lease_token')->nullable();
            $table->timestamp('send_lease_expires_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['campaign_id', 'email_normalized']);
            $table->index(['campaign_id', 'status', 'next_send_at']);
            $table->index(['mailbox_id', 'status']);
        });

        Schema::create('recipient_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('original_filename')->nullable();
            $table->string('private_path')->nullable();
            $table->char('source_hash', 64);
            $table->json('headers');
            $table->json('mapping');
            $table->unsignedInteger('mapping_version')->default(1);
            $table->string('status');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('ready')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('invalid')->default(0);
            $table->unsignedInteger('duplicate')->default(0);
            $table->unsignedInteger('suppressed')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('recipient_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_values');
            $table->string('email_address')->nullable();
            $table->string('email_normalized')->nullable();
            $table->string('full_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('company')->nullable();
            $table->string('issue');
            $table->char('row_hash', 64);
            $table->timestamps();
            $table->unique(['recipient_import_id', 'row_number']);
        });

        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('local_key')->unique();
            $table->string('remote_thread_id')->nullable();
            $table->string('subject')->default('');
            $table->string('subject_normalized')->default('');
            $table->string('snippet')->nullable();
            $table->string('participant_name')->nullable();
            $table->string('participant_email')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->string('classification')->default('unknown');
            $table->string('bounce_subtype')->nullable();
            $table->decimal('classification_confidence', 5, 2)->nullable();
            $table->string('local_state');
            $table->boolean('is_unread')->default(false);
            $table->timestamps();
            $table->unique(['mailbox_id', 'remote_thread_id']);
            $table->index(['user_id', 'local_state', 'last_message_at']);
            $table->index(['mailbox_id', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('local_key')->unique();
            $table->foreignId('thread_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('mailbox_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sequence_step_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('reply_to_message_id')->nullable();
            $table->string('remote_message_id')->nullable();
            $table->uuid('client_message_marker')->nullable();
            $table->string('internet_message_id')->nullable();
            $table->uuid('tracking_token')->nullable()->unique();
            $table->string('in_reply_to')->nullable();
            $table->json('references')->nullable();
            $table->string('direction');
            $table->string('classification')->nullable();
            $table->string('bounce_subtype')->nullable();
            $table->decimal('classification_confidence', 5, 2)->nullable();
            $table->string('status');
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->json('to_recipients');
            $table->json('cc_recipients');
            $table->json('bcc_recipients');
            $table->string('subject')->default('');
            $table->longText('body_html_sanitized')->nullable();
            $table->longText('body_text');
            $table->longText('rendered_payload')->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->json('provider_metadata');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['mailbox_id', 'remote_message_id']);
            $table->index('internet_message_id');
            $table->foreign('reply_to_message_id')->references('id')->on('messages')->nullOnDelete();
        });

        Schema::create('send_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained()->restrictOnDelete();
            $table->foreignId('message_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sequence_step_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->uuid('idempotency_key')->unique();
            $table->uuid('client_operation_id')->nullable();
            $table->string('status');
            $table->string('provider_phase')->nullable();
            $table->string('provider_draft_id')->nullable();
            $table->string('provider_request_id')->nullable();
            $table->string('provider_marker')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_acquired_at')->nullable();
            $table->timestamp('provider_accepted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('manual_review_cycle')->default(0);
            $table->string('deferred_reason')->nullable();
            $table->string('error_code')->nullable();
            $table->string('safe_error_message')->nullable();
            $table->timestamps();
            $table->unique(['campaign_recipient_id', 'sequence_step_id', 'kind']);
            $table->unique(['user_id', 'kind', 'client_operation_id']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id');
            $table->json('payload');
            $table->timestamp('available_at');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('safe_error_message')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'available_at']);
        });

        Schema::create('message_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_recipient_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('event_bucket');
            $table->timestamp('occurred_at');
            $table->string('ip_hash')->nullable();
            $table->string('user_agent_hash')->nullable();
            $table->json('metadata');
            $table->unique(['message_id', 'type', 'event_bucket']);
            $table->index(['campaign_recipient_id', 'type', 'occurred_at']);
        });

        Schema::create('suppression_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email_address');
            $table->string('email_normalized');
            $table->string('reason');
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'email_normalized']);
        });

        Schema::create('send_attempt_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('send_attempt_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('review_cycle');
            $table->string('resolution');
            $table->string('operator_identifier');
            $table->text('safe_evidence');
            $table->timestamps();
            $table->unique(['send_attempt_id', 'review_cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('send_attempt_resolutions');
        Schema::dropIfExists('suppression_entries');
        Schema::dropIfExists('message_events');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('send_attempts');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('threads');
        Schema::dropIfExists('recipient_import_rows');
        Schema::dropIfExists('recipient_imports');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaign_mailbox');
        Schema::dropIfExists('sequence_steps');
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropForeign(['draft_sequence_version_id']);
            $table->dropForeign(['published_sequence_version_id']);
        });
        Schema::dropIfExists('sequence_versions');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('mailbox_sync_states');
        Schema::dropIfExists('oauth_attempts');
        Schema::dropIfExists('mailboxes');
        Schema::dropIfExists('provider_connections');
    }
};
