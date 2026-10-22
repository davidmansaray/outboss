<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_mail_domains', function (Blueprint $table): void {
            $table->id();
            // Restrict workspace deletion while a managed domain exists. A
            // remote account must be explicitly reconciled/deleted first.
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('provider', 32)->default('stalwart');
            $table->string('managed_instance_key', 80);
            $table->string('domain', 253);
            $table->string('organisational_domain', 253);
            $table->string('domain_kind', 32);
            $table->string('remote_domain_id', 255)->nullable();
            $table->string('status', 32)->default('draft');
            $table->json('dns_records')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('dns_activated_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamps();

            // A public domain can only exist once on a given mail server,
            // regardless of which workspace raced to claim it. Keep the
            // instance in the key so a deliberately isolated server can host
            // an independent copy of the same domain.
            $table->unique(
                ['managed_instance_key', 'domain'],
                'managed_mail_domains_instance_domain_unique',
            );
            $table->index(['workspace_id', 'status'], 'managed_mail_domains_workspace_status_index');
            $table->index(
                ['managed_instance_key', 'remote_domain_id'],
                'managed_mail_domains_instance_remote_index',
            );
        });

        Schema::create('managed_mailbox_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('managed_mail_domain_id')
                ->constrained('managed_mail_domains')
                ->restrictOnDelete();
            // The normal Outboss Mailbox is linked after the remote account
            // exists. Keep the managed record if that mailbox is later
            // disconnected so reconciliation remains possible.
            $table->foreignId('mailbox_id')->nullable()->constrained('mailboxes')->nullOnDelete();
            $table->string('remote_account_id', 255)->nullable();
            $table->string('local_part', 64);
            $table->string('display_name')->nullable();
            $table->unsignedInteger('credential_version')->default(1);
            $table->string('status', 32)->default('provisioning');
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamps();

            $table->unique(
                ['managed_mail_domain_id', 'local_part'],
                'managed_mailbox_accounts_domain_local_unique',
            );
            $table->unique('mailbox_id', 'managed_mailbox_accounts_mailbox_unique');
            $table->index(
                ['managed_mail_domain_id', 'status'],
                'managed_mailbox_accounts_domain_status_index',
            );
            $table->index(
                ['remote_account_id'],
                'managed_mailbox_accounts_remote_account_index',
            );
        });

        Schema::create('managed_mail_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('managed_mail_domain_id')
                ->nullable()
                ->constrained('managed_mail_domains')
                ->nullOnDelete();
            $table->foreignId('managed_mailbox_account_id')
                ->nullable()
                ->constrained('managed_mailbox_accounts')
                ->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 24)->default('user');
            $table->string('event', 48);
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32)->nullable();
            // Metadata is a safe allow-list only. Never put credentials,
            // request bodies, or provider responses here.
            $table->json('safe_metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at'], 'managed_mail_events_workspace_created_index');
            $table->index(
                ['managed_mail_domain_id', 'created_at'],
                'managed_mail_events_domain_created_index',
            );
            $table->index(
                ['managed_mailbox_account_id', 'created_at'],
                'managed_mail_events_account_created_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_mail_events');
        Schema::dropIfExists('managed_mailbox_accounts');
        Schema::dropIfExists('managed_mail_domains');
    }
};
