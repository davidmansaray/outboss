<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Older deployments may contain rows whose compatibility source is
        // unknown or null. Keep that historical value available to the
        // lineage backfill instead of making the new source model force a
        // fabricated category. The original S1 table migrations declared the
        // column as required, so make the compatibility projection nullable
        // as part of this additive migration before adding composite keys.
        if (Schema::hasColumn('accounts', 'source')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->string('source')->nullable()->change();
            });
        }
        if (Schema::hasColumn('people', 'source')) {
            Schema::table('people', function (Blueprint $table): void {
                $table->string('source')->nullable()->change();
            });
        }

        Schema::create('prospect_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            // These pointers are wired with same-aggregate foreign keys in the
            // following migration after the child tables exist.
            $table->uuid('current_version_id')->nullable();
            $table->uuid('last_run_id')->nullable();
            $table->string('status', 24)->default('active');
            $table->string('kind', 48);
            $table->string('system_key', 160)->nullable();
            $table->string('display_name', 160);
            $table->string('name_normalized', 160);
            $table->string('active_name_normalized', 160)->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'prospect_sources_workspace_id_unique');
            $table->unique(['workspace_id', 'system_key'], 'prospect_sources_workspace_system_key_unique');
            $table->unique(
                ['workspace_id', 'active_name_normalized'],
                'prospect_sources_workspace_active_name_unique',
            );
            $table->index(['workspace_id', 'status', 'updated_at'], 'prospect_sources_workspace_status_updated_index');
        });

        Schema::create('prospect_source_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('prospect_source_id')->constrained('prospect_sources')->restrictOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('schema_version')->default(1);
            $table->json('configuration');
            $table->char('configuration_hash', 64);
            $table->json('policy_snapshot');
            $table->char('policy_hash', 64);
            $table->string('capability', 120)->nullable();
            $table->string('discovery_motion', 120)->nullable();
            $table->string('source_family', 120)->nullable();
            $table->uuid('provider_registration_id')->nullable();
            $table->string('provider_registration_version', 80)->nullable();
            $table->string('origin', 40);
            $table->string('actor_type', 32)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_display_name', 160)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['prospect_source_id', 'version'], 'prospect_source_versions_source_version_unique');
            $table->unique(['prospect_source_id', 'id'], 'prospect_source_versions_source_id_unique');
            $table->unique(
                ['workspace_id', 'prospect_source_id', 'id'],
                'prospect_source_versions_workspace_source_id_unique',
            );
            $table->index(['prospect_source_id', 'created_at'], 'prospect_source_versions_source_created_index');
        });

        Schema::create('source_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignUuid('prospect_source_id')->constrained('prospect_sources')->restrictOnDelete();
            $table->foreignUuid('prospect_source_version_id')
                ->constrained('prospect_source_versions')
                ->restrictOnDelete();
            $table->string('state', 32)->default('draft');
            $table->string('initiator_type', 32);
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('initiator_display_name', 160)->nullable();
            $table->string('idempotency_key', 160);
            $table->char('request_fingerprint', 64);
            $table->uuid('retry_of_run_id')->nullable();
            $table->foreignId('person_import_id')->nullable()->constrained('person_imports')->nullOnDelete();
            $table->foreignId('recipient_import_id')->nullable()->constrained('recipient_imports')->nullOnDelete();
            $table->json('input_reference')->nullable();
            $table->json('checkpoint')->nullable();
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('valid')->default(0);
            $table->unsignedInteger('accepted')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('linked')->default(0);
            $table->unsignedInteger('duplicate')->default(0);
            $table->unsignedInteger('conflicted')->default(0);
            $table->unsignedInteger('suppressed')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->unsignedInteger('expired')->default(0);
            $table->unsignedBigInteger('estimated_cost_micros')->nullable();
            $table->unsignedBigInteger('reserved_cost_micros')->nullable();
            $table->unsignedBigInteger('actual_cost_micros')->nullable();
            $table->string('lease_owner', 160)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('retry_after_at')->nullable();
            $table->string('safe_error_code', 80)->nullable();
            $table->string('safe_error_message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'source_runs_workspace_id_unique');
            $table->unique(['workspace_id', 'idempotency_key'], 'source_runs_workspace_id_idempotency_unique');
            $table->unique('person_import_id', 'source_runs_person_import_unique');
            $table->unique('recipient_import_id', 'source_runs_recipient_import_unique');
            $table->unique(['prospect_source_id', 'id'], 'source_runs_source_id_unique');
            $table->unique(
                ['workspace_id', 'prospect_source_id', 'id'],
                'source_runs_workspace_source_id_unique',
            );
            $table->index(['workspace_id', 'prospect_source_id', 'state'], 'source_runs_workspace_source_state_index');
            $table->index(['workspace_id', 'state', 'updated_at'], 'source_runs_workspace_state_updated_index');
        });

        Schema::create('source_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignUuid('prospect_source_id')->constrained('prospect_sources')->restrictOnDelete();
            $table->foreignUuid('prospect_source_version_id')
                ->constrained('prospect_source_versions')
                ->restrictOnDelete();
            $table->foreignUuid('source_run_id')->constrained('source_runs')->restrictOnDelete();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('input_key', 160)->nullable();
            $table->char('observation_key', 64);
            $table->string('entity_type', 24);
            $table->json('observed_values')->nullable();
            $table->char('observed_values_hash', 64)->nullable();
            $table->char('raw_input_hash', 64)->nullable();
            $table->string('provider_record_id', 255)->nullable();
            $table->string('provider_snapshot_id', 255)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->timestamp('provider_observed_at')->nullable();
            $table->timestamp('retrieved_at')->useCurrent();
            $table->string('disposition', 32)->default('pending_review');
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('match_method', 64)->nullable();
            $table->decimal('match_confidence', 5, 4)->nullable();
            $table->string('policy_classification', 80)->nullable();
            $table->char('policy_hash', 64)->nullable();
            $table->timestamp('retain_until')->nullable();
            $table->string('display_classification', 80)->nullable();
            $table->string('export_classification', 80)->nullable();
            $table->string('safe_error_code', 80)->nullable();
            $table->string('safe_error_message', 500)->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('redacted_at')->nullable();
            $table->timestamps();

            $table->unique(['source_run_id', 'observation_key'], 'source_observations_run_key_unique');
            $table->unique(['workspace_id', 'id'], 'source_observations_workspace_id_unique');
            $table->unique(
                ['workspace_id', 'prospect_source_id', 'id'],
                'source_observations_workspace_source_id_unique',
            );
            $table->unique(['workspace_id', 'source_run_id', 'id'], 'source_observations_workspace_run_id_unique');
            $table->unique(
                ['workspace_id', 'prospect_source_id', 'prospect_source_version_id', 'id'],
                'source_observations_workspace_source_version_id_unique',
            );
            $table->index(['workspace_id', 'prospect_source_id', 'disposition'], 'source_observations_workspace_source_disposition_index');
            $table->index(['workspace_id', 'entity_type', 'account_id', 'person_id'], 'source_observations_workspace_entity_index');
            $table->index(['retain_until', 'disposition'], 'source_observations_retention_index');
        });

        Schema::create('account_source_memberships', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignUuid('prospect_source_id')->constrained('prospect_sources')->restrictOnDelete();
            // Memberships are projections of a canonical record. Removing an
            // explicitly deleted canonical record removes only this projection
            // while observations remain available as lineage.
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignUuid('first_source_run_id')->constrained('source_runs')->restrictOnDelete();
            $table->foreignUuid('first_source_observation_id')->constrained('source_observations')->restrictOnDelete();
            $table->foreignUuid('last_source_run_id')->constrained('source_runs')->restrictOnDelete();
            $table->foreignUuid('last_source_observation_id')->constrained('source_observations')->restrictOnDelete();
            $table->string('state', 24)->default('active');
            $table->timestamp('first_observed_at');
            $table->timestamp('last_observed_at');
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'prospect_source_id', 'account_id'], 'account_memberships_source_account_unique');
            $table->unique(['workspace_id', 'prospect_source_id', 'public_id'], 'account_memberships_source_public_id_unique');
            $table->index(['workspace_id', 'account_id'], 'account_memberships_workspace_account_index');
        });

        Schema::create('person_source_memberships', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignUuid('prospect_source_id')->constrained('prospect_sources')->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignUuid('first_source_run_id')->constrained('source_runs')->restrictOnDelete();
            $table->foreignUuid('first_source_observation_id')->constrained('source_observations')->restrictOnDelete();
            $table->foreignUuid('last_source_run_id')->constrained('source_runs')->restrictOnDelete();
            $table->foreignUuid('last_source_observation_id')->constrained('source_observations')->restrictOnDelete();
            $table->string('state', 24)->default('active');
            $table->timestamp('first_observed_at');
            $table->timestamp('last_observed_at');
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'prospect_source_id', 'person_id'], 'person_memberships_source_person_unique');
            $table->unique(['workspace_id', 'prospect_source_id', 'public_id'], 'person_memberships_source_public_id_unique');
            $table->index(['workspace_id', 'person_id'], 'person_memberships_workspace_person_index');
        });

        Schema::create('external_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('entity_type', 24);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->string('provider', 100);
            $table->string('identity_type', 80);
            $table->text('value')->nullable();
            $table->char('value_hash', 64);
            $table->foreignUuid('first_source_observation_id')->nullable()->constrained('source_observations')->nullOnDelete();
            $table->foreignUuid('last_source_observation_id')->nullable()->constrained('source_observations')->nullOnDelete();
            $table->timestamp('first_observed_at')->nullable();
            $table->timestamp('last_observed_at')->nullable();
            $table->string('state', 24)->default('active');
            $table->char('policy_hash', 64)->nullable();
            $table->timestamp('retain_until')->nullable();
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'entity_type', 'provider', 'identity_type', 'value_hash'],
                'external_identities_workspace_identity_unique',
            );
            $table->unique(['workspace_id', 'id'], 'external_identities_workspace_id_unique');
            $table->index(['workspace_id', 'entity_type', 'account_id', 'person_id'], 'external_identities_workspace_entity_index');
        });

        Schema::create('canonical_field_provenance', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('entity_type', 24);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->string('field_path', 120);
            $table->foreignUuid('source_observation_id')->constrained('source_observations')->restrictOnDelete();
            $table->foreignUuid('source_run_id')->constrained('source_runs')->restrictOnDelete();
            $table->string('decision', 32);
            $table->char('prior_value_hash', 64)->nullable();
            $table->char('new_value_hash', 64)->nullable();
            $table->string('actor_type', 32)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_display_name', 160)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['workspace_id', 'public_id'], 'canonical_field_provenance_workspace_public_id_unique');
            $table->index(['workspace_id', 'entity_type', 'account_id', 'person_id'], 'canonical_field_provenance_workspace_entity_index');
            $table->index(['source_observation_id', 'field_path'], 'canonical_field_provenance_observation_field_index');
        });

        // The account/person tables predate S1 and do not have an explicit
        // workspace+id ownership key. Add one so all S1 composite FKs can be
        // enforced without changing their legacy columns or semantics.
        Schema::table('accounts', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'id'], 'accounts_workspace_id_unique');
        });
        Schema::table('people', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'id'], 'people_workspace_id_unique');
        });

        // MySQL rejects CHECK constraints that reference columns participating
        // in a referential action. The deferred migration installs equivalent
        // checks as database triggers for both MySQL/MariaDB and SQLite.
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->dropUnique('people_workspace_id_unique');
        });
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique('accounts_workspace_id_unique');
        });

        Schema::dropIfExists('canonical_field_provenance');
        Schema::dropIfExists('external_identities');
        Schema::dropIfExists('person_source_memberships');
        Schema::dropIfExists('account_source_memberships');
        Schema::dropIfExists('source_observations');
        Schema::dropIfExists('source_runs');
        Schema::dropIfExists('prospect_source_versions');
        Schema::dropIfExists('prospect_sources');
    }
};
