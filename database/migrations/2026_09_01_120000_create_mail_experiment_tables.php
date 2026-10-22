<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_experiments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->text('hypothesis');
            $table->string('status', 24)->default('draft');
            // The model uses Laravel's encrypted cast. The database must never
            // contain the seed in plaintext, even though it is not a password.
            $table->text('randomisation_seed');
            $table->string('primary_metric', 64)->default('human_reply_rate');
            $table->unsignedInteger('target_assignments_per_cohort')->nullable();
            $table->json('configuration')->nullable();
            $table->unsignedInteger('operator_minutes')->nullable();
            $table->unsignedBigInteger('operator_cost_micros')->nullable();
            $table->unsignedBigInteger('infrastructure_cost_micros')->nullable();
            $table->unsignedInteger('incident_count')->default(0);
            $table->json('incidents')->nullable();
            // Canonical preparation inputs are retained separately from the
            // operator-editable configuration.  Once assignments exist this
            // snapshot is the source of truth for idempotent retries.
            $table->json('assignment_snapshot')->nullable();
            $table->char('assignment_snapshot_hash', 64)->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('aborted_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('mail_experiment_cohorts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_experiment_id')
                ->constrained('mail_experiments')
                ->restrictOnDelete();
            $table->string('key', 64);
            $table->string('label');
            $table->string('domain_strategy', 32);
            $table->json('configuration')->nullable();
            $table->char('snapshot_hash', 64)->nullable();
            $table->json('mailbox_topology_snapshot')->nullable();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['mail_experiment_id', 'key'], 'mail_experiment_cohort_experiment_key_unique');
            $table->unique(['mail_experiment_id', 'campaign_id'], 'mail_experiment_cohort_experiment_campaign_unique');
            $table->index(['mail_experiment_id', 'domain_strategy'], 'mail_experiment_cohort_experiment_strategy_index');
        });

        Schema::create('mail_experiment_cohort_mailbox', function (Blueprint $table): void {
            $table->foreignId('mail_experiment_cohort_id')
                ->constrained('mail_experiment_cohorts')
                ->restrictOnDelete();
            $table->foreignId('mail_experiment_id')
                ->constrained('mail_experiments')
                ->restrictOnDelete();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->restrictOnDelete();
            $table->unsignedInteger('position');

            $table->primary(['mail_experiment_cohort_id', 'mailbox_id']);
            // The denormalised experiment ID makes the no-cross-cohort rule a
            // database invariant, rather than relying only on application code.
            $table->unique(['mail_experiment_id', 'mailbox_id'], 'mail_experiment_cohort_experiment_mailbox_unique');
            $table->unique(['mail_experiment_cohort_id', 'position'], 'mail_experiment_cohort_mailbox_position_unique');
            $table->index(['mail_experiment_id', 'mail_experiment_cohort_id'], 'mail_experiment_cohort_experiment_cohort_index');
        });

        Schema::create('mail_experiment_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_experiment_id')
                ->constrained('mail_experiments')
                ->restrictOnDelete();
            $table->foreignId('mail_experiment_cohort_id')
                ->nullable()
                ->constrained('mail_experiment_cohorts')
                ->restrictOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->restrictOnDelete();
            $table->foreignId('campaign_recipient_id')
                ->nullable()
                ->constrained('campaign_recipients')
                ->restrictOnDelete();
            $table->string('email_normalized');
            $table->string('recipient_domain');
            $table->string('recipient_provider', 24)->default('unknown');
            $table->string('stratum_key', 160);
            $table->char('assignment_key', 64);
            $table->json('provider_mx_snapshot')->nullable();
            $table->char('assignment_snapshot_hash', 64)->nullable();
            $table->timestamp('assigned_at');
            $table->string('exclusion_reason')->nullable();
            $table->timestamps();

            $table->unique(['mail_experiment_id', 'email_normalized'], 'mail_experiment_assignment_experiment_email_unique');
            // SQL unique indexes allow multiple NULL values, giving us the
            // intended "unique non-null person" invariant on both MySQL and
            // SQLite.
            $table->unique(['mail_experiment_id', 'person_id'], 'mail_experiment_assignment_experiment_person_unique');
            $table->unique(['mail_experiment_id', 'assignment_key'], 'mail_experiment_assignment_experiment_key_unique');
            $table->index(['mail_experiment_id', 'mail_experiment_cohort_id'], 'mail_experiment_assignment_cohort_index');
            $table->index(['mail_experiment_id', 'recipient_provider'], 'mail_experiment_assignment_provider_index');
            $table->index(['workspace_id', 'email_normalized'], 'mail_experiment_assignment_workspace_email_index');
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->foreignId('mail_experiment_cohort_id')
                ->nullable()
                ->after('workspace_id')
                ->constrained('mail_experiment_cohorts')
                ->nullOnDelete();
            $table->index(['workspace_id', 'mail_experiment_cohort_id'], 'campaigns_workspace_experiment_cohort_index');
        });

    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropForeign(['mail_experiment_cohort_id']);
            $table->dropIndex('campaigns_workspace_experiment_cohort_index');
            $table->dropColumn('mail_experiment_cohort_id');
        });

        Schema::dropIfExists('mail_experiment_assignments');
        Schema::dropIfExists('mail_experiment_cohort_mailbox');
        Schema::dropIfExists('mail_experiment_cohorts');
        Schema::dropIfExists('mail_experiments');
    }
};
