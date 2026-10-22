<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Services\ScheduleDueCampaignSends;
use App\Domain\Campaigns\Services\SendCampaignMessage;
use App\Domain\Mail\DTOs\MailExperimentRuntimeDecision;
use App\Domain\Mail\Enums\AuthScheme;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailExperimentDomainStrategy;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Enums\ManagedMailDomainKind;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use App\Domain\Mail\Enums\RecipientMailboxProvider;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Services\ExperimentMetricsService;
use App\Domain\Mail\Services\MailExperimentRuntimeGuard;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\MailExperiment;
use App\Models\MailExperimentAssignment;
use App\Models\MailExperimentCohort;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\Message;
use App\Models\ProviderConnection;
use App\Models\SendAttempt;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\Config;

/** @return array{experiment:MailExperiment,cohort:MailExperimentCohort,campaign:Campaign,mailbox:Mailbox,domain:ManagedMailDomain,recipient:CampaignRecipient,attempt:SendAttempt} */
function runtimeGuardFixture(): array
{
    $owner = User::factory()->create();
    $workspaceId = $owner->currentWorkspace()->id;
    $experiment = MailExperiment::factory()->create([
        'created_by_user_id' => $owner->id,
        'workspace_id' => $workspaceId,
        'status' => 'ready',
    ]);
    $campaign = Campaign::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspaceId,
        'status' => CampaignStatus::Active,
        'business_days_only' => false,
    ]);
    $cohort = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'campaign_id' => $campaign->id,
        'key' => 'a',
        'domain_strategy' => MailExperimentDomainStrategy::Subdomain,
    ]);
    $campaign->forceFill(['mail_experiment_cohort_id' => $cohort->id])->save();

    $domain = ManagedMailDomain::factory()->create([
        'workspace_id' => $workspaceId,
        'created_by_user_id' => $owner->id,
        'domain' => 'runtime-'.$experiment->id.'.example.test',
        'organisational_domain' => 'example.test',
        'domain_kind' => ManagedMailDomainKind::Subdomain,
        'provider' => MailProviderName::Stalwart,
        'status' => ManagedMailDomainStatus::Active,
        'verified_at' => now()->subDay(),
        'last_verified_at' => now()->subHour(),
    ]);
    $connection = ProviderConnection::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspaceId,
        'provider' => MailProviderName::Stalwart,
        'connection_kind' => ConnectionKind::Managed,
        'auth_scheme' => AuthScheme::AppPassword,
        'access_token' => null,
        'refresh_token' => null,
        'credential_payload' => ['username' => 'sender@'.$domain->domain, 'password' => 'test-password'],
        'status' => ConnectionStatus::Connected,
    ]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspaceId,
        'provider_connection_id' => $connection->id,
        'email_address' => 'sender@'.$domain->domain,
        'status' => ConnectionStatus::Connected,
        'provider_metadata' => ['managed_mail_domain_id' => $domain->id],
    ]);
    ManagedMailboxAccount::factory()->create([
        'managed_mail_domain_id' => $domain->id,
        'mailbox_id' => $mailbox->id,
        'local_part' => 'sender',
        'status' => ManagedMailboxAccountStatus::Active,
        'provisioned_at' => now()->subDay(),
        'last_verified_at' => now()->subHour(),
    ]);
    $cohort->mailboxes()->attach($mailbox->id, ['position' => 1, 'mail_experiment_id' => $experiment->id]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $step = $campaign->draftVersion->activeSteps->firstOrFail();
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $workspaceId,
        'mailbox_id' => $mailbox->id,
        'sequence_version_id' => $campaign->draft_sequence_version_id,
        'next_sequence_step_id' => $step->id,
        'next_send_at' => now()->subMinute(),
        'status' => RecipientStatus::Active,
        'email_address' => 'target@example.test',
        'email_normalized' => 'target@example.test',
    ]);
    $thread = Thread::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspaceId,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
    ]);
    $message = Message::factory()->outbound()->create([
        'workspace_id' => $workspaceId,
        'mailbox_id' => $mailbox->id,
        'thread_id' => $thread->id,
        'campaign_recipient_id' => $recipient->id,
        'sequence_step_id' => $step->id,
        'from_email' => $mailbox->email_address,
        'to_recipients' => [['email' => $recipient->email_address]],
        'remote_message_id' => null,
    ]);
    $attempt = SendAttempt::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspaceId,
        'mailbox_id' => $mailbox->id,
        'message_id' => $message->id,
        'campaign_recipient_id' => $recipient->id,
        'sequence_step_id' => $step->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Queued,
    ]);

    // All cohort audience rows must exist before the experiment is frozen.
    // Runtime tests then exercise the same post-freeze state as production.
    $experiment->forceFill([
        'status' => 'running',
        'frozen_at' => now(),
        'started_at' => now(),
    ])->save();

    Config::set([
        'outboss.campaign_sending_enabled' => true,
    ]);

    return compact('experiment', 'cohort', 'campaign', 'mailbox', 'domain', 'recipient', 'attempt');
}

it('pauses a managed experiment and defers queued work when persisted domain evidence is no longer active', function () {
    $fixture = runtimeGuardFixture();
    $fixture['domain']->forceFill(['status' => ManagedMailDomainStatus::Suspended])->save();

    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(0)
        ->and($fixture['experiment']->fresh()->status->value)->toBe('paused')
        ->and($fixture['campaign']->fresh()->status)->toBe(CampaignStatus::Paused)
        ->and($fixture['attempt']->fresh()->status)->toBe(SendAttemptStatus::Deferred)
        ->and($fixture['attempt']->fresh()->deferred_reason)->toBe('managed_domain_unavailable')
        ->and($fixture['experiment']->fresh()->incidents[0]['reason'])->toBe('managed_domain_unavailable');
});

it('fails closed when a linked experiment mailbox has no managed account record', function (): void {
    $fixture = runtimeGuardFixture();
    ManagedMailboxAccount::query()
        ->where('mailbox_id', $fixture['mailbox']->id)
        ->delete();

    $decision = app(MailExperimentRuntimeGuard::class)->check($fixture['campaign'], $fixture['mailbox']);

    expect($decision)->toEqual(new MailExperimentRuntimeDecision(false, 'managed_domain_unavailable', true))
        ->and($fixture['experiment']->fresh()->status->value)->toBe('paused')
        ->and($fixture['campaign']->fresh()->status)->toBe(CampaignStatus::Paused);
});

it('does not schedule a post-launch cohort recipient without its frozen assignment', function (): void {
    $fixture = runtimeGuardFixture();

    $decision = app(MailExperimentRuntimeGuard::class)->check(
        $fixture['campaign'],
        $fixture['mailbox'],
        $fixture['recipient'],
    );

    expect($decision)->toEqual(new MailExperimentRuntimeDecision(false, 'recipient_not_in_frozen_assignment', false))
        ->and(app(ScheduleDueCampaignSends::class)->handle())->toBe(0)
        ->and(SendAttempt::query()->where('campaign_recipient_id', $fixture['recipient']->id)->count())->toBe(1)
        ->and($fixture['attempt']->fresh()->status)->toBe(SendAttemptStatus::Queued);
});

it('blocks a leased send before provider submission when the managed domain is suspended', function () {
    $fixture = runtimeGuardFixture();
    $fixture['domain']->forceFill(['status' => ManagedMailDomainStatus::Suspended])->save();

    app(SendCampaignMessage::class)->handle($fixture['attempt']->id);

    expect($fixture['attempt']->fresh()->status)->toBe(SendAttemptStatus::Deferred)
        ->and($fixture['attempt']->fresh()->deferred_reason)->toBe('managed_domain_unavailable')
        ->and($fixture['attempt']->fresh()->attempt_count)->toBe(0)
        ->and($fixture['campaign']->fresh()->status)->toBe(CampaignStatus::Paused)
        ->and($fixture['experiment']->fresh()->status->value)->toBe('paused');
});

it('pauses all cohort work transactionally when unknown first-attempt rate exceeds the configured threshold', function () {
    $fixture = runtimeGuardFixture();
    Config::set('outboss.experiments.unknown_manual_review_rate_threshold', 0.50);
    MailExperimentAssignment::factory()->create([
        'mail_experiment_id' => $fixture['experiment']->id,
        'mail_experiment_cohort_id' => $fixture['cohort']->id,
        'workspace_id' => $fixture['experiment']->workspace_id,
        'campaign_id' => $fixture['campaign']->id,
        'campaign_recipient_id' => $fixture['recipient']->id,
        'email_normalized' => $fixture['recipient']->email_normalized,
        'recipient_domain' => 'example.test',
        'recipient_provider' => RecipientMailboxProvider::Unknown,
        'stratum_key' => 'unknown',
        'assignment_key' => hash('sha256', 'runtime-unknown'),
        'assigned_at' => now()->subHour(),
    ]);
    $fixture['attempt']->forceFill([
        'status' => SendAttemptStatus::Unknown,
        'attempt_count' => 1,
        'lease_acquired_at' => now()->subHour(),
    ])->save();

    $decision = app(MailExperimentRuntimeGuard::class)->check($fixture['campaign'], $fixture['mailbox']);

    expect($decision)->toEqual(new MailExperimentRuntimeDecision(false, 'unknown_manual_review_threshold', true))
        ->and($fixture['experiment']->fresh()->status->value)->toBe('paused')
        ->and($fixture['campaign']->fresh()->status)->toBe(CampaignStatus::Paused);
});

it('keeps provider-error and unknown rates bounded and separates recipient domains from sending domains', function () {
    $fixture = runtimeGuardFixture();
    $experiment = $fixture['experiment'];
    $cohort = $fixture['cohort'];
    $campaign = $fixture['campaign'];
    $mailbox = $fixture['mailbox'];
    $recipient = $fixture['recipient'];
    $assignment = MailExperimentAssignment::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'mail_experiment_cohort_id' => $cohort->id,
        'workspace_id' => $experiment->workspace_id,
        'campaign_id' => $campaign->id,
        'campaign_recipient_id' => $recipient->id,
        'email_normalized' => $recipient->email_normalized,
        'recipient_domain' => 'recipient.example.test',
        'recipient_provider' => RecipientMailboxProvider::Google,
        'stratum_key' => 'google',
        'assignment_key' => hash('sha256', 'runtime-metrics-one'),
        'assigned_at' => now()->subHour(),
    ]);
    MailExperimentAssignment::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'mail_experiment_cohort_id' => $cohort->id,
        'workspace_id' => $experiment->workspace_id,
        'campaign_id' => $campaign->id,
        'campaign_recipient_id' => null,
        'email_normalized' => 'excluded@example.test',
        'recipient_domain' => 'excluded.example.test',
        'recipient_provider' => RecipientMailboxProvider::Unknown,
        'stratum_key' => 'unknown',
        'assignment_key' => hash('sha256', 'runtime-metrics-excluded'),
        'assigned_at' => now()->subHour(),
        'exclusion_reason' => 'manual_review',
    ]);
    $fixture['attempt']->forceFill([
        'status' => SendAttemptStatus::Unknown,
        'attempt_count' => 1,
        'lease_acquired_at' => now()->subHour(),
        'error_code' => 'provider_timeout',
    ])->save();

    $report = app(ExperimentMetricsService::class)->report($experiment);
    $bucket = $report['summary'];

    expect($bucket['attempted_count'])->toBe(1)
        ->and($bucket['eligible_count'])->toBe(1)
        ->and($bucket['excluded_count'])->toBe(1)
        ->and($bucket['provider_attempt_count'])->toBe(1)
        ->and($bucket['provider_error_rate'])->toBe(1.0)
        ->and($bucket['unknown_or_manual_review_rate'])->toBe(1.0)
        ->and($report['domains'])->toHaveKey('runtime-'.$experiment->id.'.example.test')
        ->and($report['domains'])->not->toHaveKey('recipient.example.test')
        ->and($report['recipient_domains'])->toHaveKey('recipient.example.test');
});
