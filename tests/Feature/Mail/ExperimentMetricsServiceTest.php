<?php

use App\Domain\Mail\Enums\BounceSubtype;
use App\Domain\Mail\Enums\MailExperimentDomainStrategy;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\RecipientMailboxProvider;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\SyncScope;
use App\Domain\Mail\Enums\SyncStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Services\ExperimentMetricsService;
use App\Domain\Mail\Services\ManagedMailEventRecorder;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\MailExperiment;
use App\Models\MailExperimentCohort;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\Message;
use App\Models\SendAttempt;
use App\Models\Thread;
use Carbon\Carbon;

/**
 * @return array{experiment: MailExperiment, mailbox: Mailbox, recipient: CampaignRecipient}
 */
function seedExperimentMetricsAssignment(string $email = 'metrics@example.test'): array
{
    $experiment = MailExperiment::factory()->create();
    $cohort = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'a',
        'domain_strategy' => MailExperimentDomainStrategy::Subdomain,
    ]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $experiment->created_by_user_id,
        'workspace_id' => $experiment->workspace_id,
        'email_address' => 'sender@metrics.test',
    ]);
    $campaign = Campaign::factory()->create([
        'user_id' => $experiment->created_by_user_id,
        'workspace_id' => $experiment->workspace_id,
    ]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $experiment->workspace_id,
        'mailbox_id' => $mailbox->id,
        'email_address' => $email,
        'email_normalized' => $email,
    ]);
    $experiment->assignments()->create([
        'mail_experiment_cohort_id' => $cohort->id,
        'workspace_id' => $experiment->workspace_id,
        'campaign_id' => $campaign->id,
        'campaign_recipient_id' => $recipient->id,
        'email_normalized' => $email,
        'recipient_domain' => 'example.test',
        'recipient_provider' => RecipientMailboxProvider::Google,
        'stratum_key' => 'google',
        'assignment_key' => hash('sha256', $email),
        'provider_mx_snapshot' => ['aspmx.l.google.com'],
        'assigned_at' => now(),
    ]);

    return compact('experiment', 'mailbox', 'recipient');
}

it('excludes lease-only preflight failures from provider-attempt denominators', function () {
    $fixture = seedExperimentMetricsAssignment('lease-only@example.test');
    SendAttempt::factory()->create([
        'user_id' => $fixture['mailbox']->user_id,
        'workspace_id' => $fixture['experiment']->workspace_id,
        'mailbox_id' => $fixture['mailbox']->id,
        'campaign_recipient_id' => $fixture['recipient']->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Deferred,
        'lease_acquired_at' => now(),
        'attempt_count' => 0,
        'error_code' => 'credential_unavailable',
    ]);

    $metrics = app(ExperimentMetricsService::class);
    $summary = $metrics->report($fixture['experiment'])['summary'];
    $runtime = $metrics->runtimeUnknownManualReview($fixture['experiment']);

    expect($summary['attempted_count'])->toBe(0)
        ->and($summary['provider_attempt_count'])->toBe(0)
        ->and($summary['provider_error_rate'])->toBeNull()
        ->and($runtime['attempted_count'])->toBe(0)
        ->and($runtime['rate'])->toBeNull();
});

it('retains ambiguous and provider-error history after successful reconciliation', function () {
    $fixture = seedExperimentMetricsAssignment('reconciled@example.test');
    $message = Message::factory()->outbound()->create([
        'workspace_id' => $fixture['experiment']->workspace_id,
        'mailbox_id' => $fixture['mailbox']->id,
        'campaign_recipient_id' => $fixture['recipient']->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);
    SendAttempt::factory()->create([
        'user_id' => $fixture['mailbox']->user_id,
        'workspace_id' => $fixture['experiment']->workspace_id,
        'mailbox_id' => $fixture['mailbox']->id,
        'message_id' => $message->id,
        'campaign_recipient_id' => $fixture['recipient']->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Sent,
        'provider_attempted_at' => now()->subMinute(),
        'provider_accepted_at' => now(),
        'sent_at' => now(),
        'attempt_count' => 1,
        'provider_unknown_count' => 1,
        'provider_manual_review_count' => 1,
        'provider_error_count' => 1,
        'manual_review_cycle' => 1,
        'error_code' => null,
    ]);

    $metrics = app(ExperimentMetricsService::class);
    $report = $metrics->report($fixture['experiment']);
    $summary = $report['summary'];
    $runtime = $metrics->runtimeUnknownManualReview($fixture['experiment']);

    expect($summary['exposed_count'])->toBe(1)
        ->and($summary['unknown_first_attempt_count'])->toBe(1)
        ->and($summary['manual_review_first_attempt_count'])->toBe(1)
        ->and($summary['unknown_or_manual_review_rate'])->toBe(1.0)
        ->and($summary['provider_error_attempt_count'])->toBe(1)
        ->and($summary['provider_error_rate'])->toBe(1.0)
        ->and($runtime['attempted_count'])->toBe(1)
        ->and($runtime['unknown_manual_review_count'])->toBe(1)
        ->and($report['evidence'][0]['provider_unknown_count'])->toBe(1)
        ->and($report['evidence'][0]['provider_manual_review_count'])->toBe(1)
        ->and($report['evidence'][0]['provider_error_count'])->toBe(1);
});

it('exports recovered operational history incidents and daily ramp breaches', function () {
    $fixture = seedExperimentMetricsAssignment('operations@example.test');
    $experiment = $fixture['experiment'];
    $experiment->forceFill([
        'assignment_snapshot' => ['ramp' => ['initial_per_mailbox' => 1]],
        'assignment_snapshot_hash' => hash('sha256', 'operations-snapshot'),
    ])->save();
    $experiment->forceFill([
        'frozen_at' => now()->subDay(),
        'started_at' => now()->subDay(),
        'incidents' => [[
            'type' => 'operational_deviation',
            'reason' => 'manual_pause_reviewed',
            'at' => now()->subHour()->toIso8601String(),
        ]],
        'incident_count' => 1,
    ])->save();

    $domain = ManagedMailDomain::factory()->create([
        'workspace_id' => $experiment->workspace_id,
        'created_by_user_id' => $experiment->created_by_user_id,
        'domain' => 'metrics.test',
        'organisational_domain' => 'metrics.test',
    ]);
    $account = ManagedMailboxAccount::factory()->create([
        'managed_mail_domain_id' => $domain->id,
        'mailbox_id' => $fixture['mailbox']->id,
    ]);
    $recorder = app(ManagedMailEventRecorder::class);
    $workspace = $experiment->workspace;
    $recorder->record($workspace, 'mailbox_sending_paused', domain: $domain, account: $account);
    $recorder->record(
        $workspace,
        'mailbox_sync_failed',
        domain: $domain,
        account: $account,
        metadata: ['scope' => 'inbox'],
    );
    $recorder->record(
        $workspace,
        'mailbox_sync_recovered',
        domain: $domain,
        account: $account,
        metadata: ['scope' => 'inbox', 'duration_seconds' => 91],
    );
    $recorder->record($workspace, 'mailbox_sending_resumed', domain: $domain, account: $account);

    foreach ([now()->subMinutes(2), now()->subMinute()] as $acceptedAt) {
        SendAttempt::factory()->create([
            'user_id' => $fixture['mailbox']->user_id,
            'workspace_id' => $experiment->workspace_id,
            'mailbox_id' => $fixture['mailbox']->id,
            'campaign_recipient_id' => $fixture['recipient']->id,
            'kind' => SendAttemptKind::Campaign,
            'status' => SendAttemptStatus::Sent,
            'provider_attempted_at' => $acceptedAt,
            'provider_accepted_at' => $acceptedAt,
            'sent_at' => $acceptedAt,
            'attempt_count' => 1,
        ]);
    }

    $report = app(ExperimentMetricsService::class)->report($experiment->fresh());
    $daily = $report['operational_evidence']['mailbox_daily_sends'][0];

    expect($report['summary']['mailbox_health_pause_count'])->toBe(1)
        ->and($report['summary']['mailbox_health_pause_event_count'])->toBe(1)
        ->and($report['summary']['sync_failure_count'])->toBe(1)
        ->and($report['summary']['sync_failure_event_count'])->toBe(1)
        ->and($report['summary']['sync_failure_duration_seconds'])->toBe(91)
        ->and($report['operator']['incidents'][0]['reason'])->toBe('manual_pause_reviewed')
        ->and($report['operational_evidence']['managed_mail_events'])->toHaveCount(4)
        ->and($report['operational_evidence']['approved_ramp'])->toBe(['initial_per_mailbox' => 1])
        ->and($daily['count'])->toBe(2)
        ->and($daily['effective_daily_cap'])->toBe(1)
        ->and($daily['cap_exceeded'])->toBeTrue();
});

it('correlates first-message hard bounces to the first outbound message', function () {
    $experiment = MailExperiment::factory()->create();
    $cohort = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'key' => 'a',
        'domain_strategy' => MailExperimentDomainStrategy::Subdomain,
    ]);
    $mailbox = Mailbox::factory()->create([
        'user_id' => $experiment->created_by_user_id,
        'workspace_id' => $experiment->workspace_id,
        'email_address' => 'sender@example.test',
    ]);
    $campaign = Campaign::factory()->create([
        'user_id' => $experiment->created_by_user_id,
        'workspace_id' => $experiment->workspace_id,
    ]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $experiment->workspace_id,
        'mailbox_id' => $mailbox->id,
        'email_address' => 'person@example.test',
        'email_normalized' => 'person@example.test',
        'status' => 'bounced',
        'first_sent_at' => now()->subHour(),
    ]);
    $experiment->assignments()->create([
        'mail_experiment_cohort_id' => $cohort->id,
        'workspace_id' => $experiment->workspace_id,
        'campaign_id' => $campaign->id,
        'campaign_recipient_id' => $recipient->id,
        'email_normalized' => 'person@example.test',
        'recipient_domain' => 'example.test',
        'recipient_provider' => RecipientMailboxProvider::Google,
        'stratum_key' => 'google',
        'assignment_key' => hash('sha256', 'person@example.test'),
        'provider_mx_snapshot' => ['aspmx.l.google.com'],
        'assigned_at' => now()->subHour(),
    ]);

    $thread = Thread::factory()->create([
        'user_id' => $experiment->created_by_user_id,
        'workspace_id' => $experiment->workspace_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
    ]);
    $firstMessage = Message::factory()->outbound()->create([
        'workspace_id' => $experiment->workspace_id,
        'mailbox_id' => $mailbox->id,
        'thread_id' => $thread->id,
        'campaign_recipient_id' => $recipient->id,
        'internet_message_id' => 'first@example.test',
        'status' => 'sent',
        'sent_at' => now()->subMinutes(40),
    ]);
    $firstAttempt = SendAttempt::factory()->create([
        'workspace_id' => $experiment->workspace_id,
        'mailbox_id' => $mailbox->id,
        'message_id' => $firstMessage->id,
        'campaign_recipient_id' => $recipient->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Sent,
        'created_at' => now()->subMinutes(40),
        'sent_at' => now()->subMinutes(40),
    ]);
    $followUpMessage = Message::factory()->outbound()->create([
        'workspace_id' => $experiment->workspace_id,
        'mailbox_id' => $mailbox->id,
        'thread_id' => $thread->id,
        'campaign_recipient_id' => $recipient->id,
        'internet_message_id' => 'follow-up@example.test',
        'status' => 'sent',
        'sent_at' => now()->subMinutes(30),
    ]);
    SendAttempt::factory()->create([
        'workspace_id' => $experiment->workspace_id,
        'mailbox_id' => $mailbox->id,
        'message_id' => $followUpMessage->id,
        'campaign_recipient_id' => $recipient->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Sent,
        'created_at' => now()->subMinutes(30),
        'sent_at' => now()->subMinutes(30),
    ]);
    $bounce = Message::factory()->create([
        'workspace_id' => $experiment->workspace_id,
        'mailbox_id' => $mailbox->id,
        'thread_id' => $thread->id,
        'campaign_recipient_id' => $recipient->id,
        'direction' => MessageDirection::Inbound,
        'classification' => ThreadClassification::Bounce,
        'bounce_subtype' => BounceSubtype::Hard,
        'reply_to_message_id' => $followUpMessage->id,
        'in_reply_to' => $followUpMessage->internet_message_id,
        'received_at' => now()->subMinutes(20),
    ]);

    $summary = app(ExperimentMetricsService::class)->report($experiment)['summary'];
    $evidence = app(ExperimentMetricsService::class)->report($experiment)['evidence'][0];

    expect($firstAttempt->fresh()->message_id)->toBe($firstMessage->id)
        ->and($summary['exposed_count'])->toBe(1)
        ->and($summary['bounced_count'])->toBe(0)
        ->and($summary['first_message_hard_bounce_rate'])->toBe(0.0)
        ->and($evidence['first_message_hard_bounce'])->toBeFalse();

    $bounce->forceFill([
        'reply_to_message_id' => $firstMessage->id,
        'in_reply_to' => $firstMessage->internet_message_id,
    ])->save();
    $correlated = app(ExperimentMetricsService::class)->report($experiment)['summary'];

    expect($correlated['bounced_count'])->toBe(1)
        ->and($correlated['first_message_hard_bounce_rate'])->toBe(1.0);
});

it('deduplicates sync failures by mailbox and scope while retaining duration', function () {
    $clock = Carbon::parse('2026-09-01 12:00:00');
    Carbon::setTestNow($clock);

    try {
        $experiment = MailExperiment::factory()->create();
        $cohort = MailExperimentCohort::factory()->create([
            'mail_experiment_id' => $experiment->id,
            'key' => 'a',
            'domain_strategy' => MailExperimentDomainStrategy::Subdomain,
        ]);
        $mailbox = Mailbox::factory()->create([
            'user_id' => $experiment->created_by_user_id,
            'workspace_id' => $experiment->workspace_id,
            'email_address' => 'sender@example.test',
        ]);
        $campaign = Campaign::factory()->create([
            'user_id' => $experiment->created_by_user_id,
            'workspace_id' => $experiment->workspace_id,
        ]);

        foreach (['one@example.test', 'two@example.test'] as $email) {
            $recipient = CampaignRecipient::factory()->create([
                'campaign_id' => $campaign->id,
                'workspace_id' => $experiment->workspace_id,
                'mailbox_id' => $mailbox->id,
                'email_address' => $email,
                'email_normalized' => $email,
            ]);
            $experiment->assignments()->create([
                'mail_experiment_cohort_id' => $cohort->id,
                'workspace_id' => $experiment->workspace_id,
                'campaign_id' => $campaign->id,
                'campaign_recipient_id' => $recipient->id,
                'email_normalized' => $email,
                'recipient_domain' => 'example.test',
                'recipient_provider' => RecipientMailboxProvider::Google,
                'stratum_key' => 'google',
                'assignment_key' => hash('sha256', $email),
                'provider_mx_snapshot' => ['aspmx.l.google.com'],
                'assigned_at' => $clock,
            ]);
        }

        MailboxSyncState::factory()->create([
            'mailbox_id' => $mailbox->id,
            'scope' => SyncScope::Inbox,
            'status' => SyncStatus::Failed,
            'last_started_at' => $clock->copy()->subMinutes(5),
            'last_error' => 'inbox unavailable',
        ]);
        MailboxSyncState::factory()->create([
            'mailbox_id' => $mailbox->id,
            'scope' => SyncScope::Sent,
            'status' => SyncStatus::Failed,
            'last_started_at' => $clock->copy()->subMinutes(7),
            'last_error' => 'sent unavailable',
        ]);

        $summary = app(ExperimentMetricsService::class)->report($experiment)['summary'];

        expect($summary['assignment_count'])->toBe(2)
            ->and($summary['sync_failure_count'])->toBe(1)
            ->and($summary['sync_failure_event_count'])->toBe(2)
            ->and($summary['sync_failure_duration_seconds'])->toBe(720);
    } finally {
        Carbon::setTestNow();
    }
});
