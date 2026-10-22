<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Domain\Campaigns\Services\ScheduleDueCampaignSends;
use App\Domain\Campaigns\Services\SendCampaignMessage;
use App\Domain\Mail\Contracts\MailTransport;
use App\Domain\Mail\DTOs\RemoteMessage;
use App\Domain\Mail\DTOs\RemoteSenderIdentity;
use App\Domain\Mail\DTOs\SendReconciliationResult;
use App\Domain\Mail\DTOs\SendResult;
use App\Domain\Mail\Enums\AuthScheme;
use App\Domain\Mail\Enums\ConnectionKind;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailExperimentDomainStrategy;
use App\Domain\Mail\Enums\MailProviderName;
use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Enums\ManagedMailDomainKind;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Enums\RecipientMailboxProvider;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\SendResolution;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Providers\ProviderRegistry;
use App\Domain\Mail\Services\ApplyInboundCampaignEffects;
use App\Domain\Mail\Services\EnsureConnectionUsable;
use App\Domain\Mail\Services\MailboxSenderIdentityService;
use App\Domain\Mail\Services\ManagedMailCapability;
use App\Domain\Mail\Services\PublishOutboxEvents;
use App\Domain\Mail\Services\ReconcileUnknownSend;
use App\Domain\Mail\Services\ResolveManualReviewSend;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\MailExperiment;
use App\Models\MailExperimentCohort;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\Message;
use App\Models\ProviderConnection;
use App\Models\SendAttempt;
use App\Models\SequenceStep;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

function launchedCampaign(): array
{
    FakeMailProvider::reset();
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id, 'business_days_only' => false]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'sequence_version_id' => $campaign->draft_sequence_version_id,
        'next_sequence_step_id' => $step->id,
        'status' => RecipientStatus::Queued,
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
        'first_name' => 'Maya',
        'company' => 'Stripe',
    ]);

    app(LaunchCampaignService::class)->handle($campaign->fresh(), $user);

    return [$user, $mailbox, $campaign->fresh(), $recipient->fresh(), $step];
}

function sendDueCampaigns(): int
{
    Config::set('outboss.campaign_sending_enabled', true);
    $created = app(ScheduleDueCampaignSends::class)->handle();
    app(PublishOutboxEvents::class)->handle();

    return $created;
}

function killSwitchExperimentCampaign(Campaign $campaign, User $user): MailExperiment
{
    // Runtime experiment checks intentionally fail closed for every linked
    // experiment. Build the sender's managed records here instead of relying
    // on the old ordinary-mailbox fixture bypass.
    $mailbox = $campaign->mailboxes()->firstOrFail();
    $domain = ManagedMailDomain::factory()->create([
        'workspace_id' => $campaign->getAttribute('workspace_id'),
        'created_by_user_id' => $user->id,
        'domain' => 'campaign-'.$campaign->id.'.example.test',
        'organisational_domain' => 'example.test',
        'domain_kind' => ManagedMailDomainKind::Subdomain,
        'provider' => MailProviderName::Stalwart,
        'status' => ManagedMailDomainStatus::Active,
        'verified_at' => now()->subDay(),
        'last_verified_at' => now()->subHour(),
    ]);
    $connection = ProviderConnection::query()->findOrFail($mailbox->provider_connection_id);
    $connection->forceFill([
        'provider' => MailProviderName::Stalwart,
        'connection_kind' => ConnectionKind::Managed,
        'auth_scheme' => AuthScheme::AppPassword,
        'access_token' => null,
        'refresh_token' => null,
        'credential_payload' => ['username' => $mailbox->email_address, 'password' => 'test-password'],
        'status' => ConnectionStatus::Connected,
    ])->save();
    $mailbox->forceFill([
        'provider_metadata' => ['managed_mail_domain_id' => $domain->id],
        'status' => ConnectionStatus::Connected,
    ])->save();
    ManagedMailboxAccount::factory()->create([
        'managed_mail_domain_id' => $domain->id,
        'mailbox_id' => $mailbox->id,
        'local_part' => strstr($mailbox->email_address, '@', true) ?: 'sender',
        'status' => ManagedMailboxAccountStatus::Active,
        'provisioned_at' => now()->subDay(),
        'last_verified_at' => now()->subHour(),
    ]);

    $experiment = MailExperiment::factory()->create([
        'workspace_id' => $campaign->getAttribute('workspace_id'),
        'created_by_user_id' => $user->id,
    ]);
    $cohort = MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'campaign_id' => $campaign->id,
        'key' => 'a',
        'domain_strategy' => MailExperimentDomainStrategy::Subdomain,
    ]);
    $campaign->forceFill(['mail_experiment_cohort_id' => $cohort->id])->save();
    $cohort->mailboxes()->attach($mailbox->id, [
        'position' => 1,
        'mail_experiment_id' => $experiment->id,
    ]);
    $recipient = $campaign->recipients()->firstOrFail();
    $experiment->assignments()->create([
        'mail_experiment_cohort_id' => $cohort->id,
        'workspace_id' => $experiment->workspace_id,
        'campaign_id' => $campaign->id,
        'campaign_recipient_id' => $recipient->id,
        'email_normalized' => $recipient->email_normalized,
        'recipient_domain' => 'stripe.com',
        'recipient_provider' => RecipientMailboxProvider::Unknown,
        'stratum_key' => RecipientMailboxProvider::Unknown->value,
        'assignment_key' => hash('sha256', 'kill-switch-'.$experiment->id),
        'provider_mx_snapshot' => [],
        'assigned_at' => now(),
    ]);
    $experiment->forceFill([
        'status' => 'running',
        'frozen_at' => now(),
        'started_at' => now(),
    ])->save();

    return $experiment;
}

/**
 * @return array{primary: string, alternate: string}
 */
function configureCampaignZohoIdentities(Mailbox $mailbox): array
{
    ProviderConnection::query()
        ->findOrFail($mailbox->provider_connection_id)
        ->forceFill(['provider' => MailProviderName::Zoho])
        ->save();
    $primary = $mailbox->email_address;
    $alternate = 'partnerships@outboss.io';

    app(MailboxSenderIdentityService::class)->sync($mailbox->fresh('connection'), [
        new RemoteSenderIdentity(
            key: 'provider-primary',
            emailAddress: $primary,
            displayName: 'Primary sender',
            mode: 'primary',
        ),
        new RemoteSenderIdentity(
            key: 'provider-alternate',
            emailAddress: $alternate,
            displayName: 'Partnerships',
            mode: 'alias',
        ),
    ], supported: true);

    return ['primary' => $primary, 'alternate' => $alternate];
}

it('sends a campaign step once and advances the recipient', function () {
    $this->travelTo(now()->setTime(10, 0));
    [, , $campaign, $recipient] = launchedCampaign();

    sendDueCampaigns();
    sendDueCampaigns();

    expect(SendAttempt::query()->where('kind', SendAttemptKind::Campaign)->count())->toBe(1)
        ->and($recipient->fresh()->first_sent_at)->not->toBeNull()
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Completed)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Completed)
        ->and(FakeMailProvider::$store['sends'])->toHaveCount(1);
});

it('uses the mailbox current identity for campaign follow-ups while retaining the first message snapshot', function () {
    $this->travelTo(now()->setTime(10, 0));
    [, $mailbox, , $recipient, $step] = launchedCampaign();
    $identities = configureCampaignZohoIdentities($mailbox);
    SequenceStep::factory()->create([
        'sequence_version_id' => $step->sequence_version_id,
        'position' => 2,
        'title' => 'Follow-up from current identity',
        'delay_minutes' => 0,
        'send_as' => SequenceSendAs::Reply,
        'body_template' => 'Following up.',
    ]);

    sendDueCampaigns();
    $first = Message::query()
        ->where('campaign_recipient_id', $recipient->id)
        ->where('direction', MessageDirection::Outbound)
        ->firstOrFail();

    app(MailboxSenderIdentityService::class)->select(
        $mailbox->fresh(),
        MailboxSenderIdentityService::keyForEmail($identities['alternate']),
    );
    sendDueCampaigns();

    $messages = Message::query()
        ->where('campaign_recipient_id', $recipient->id)
        ->where('direction', MessageDirection::Outbound)
        ->orderBy('id')
        ->get();

    expect($mailbox->fresh()->selected_sender_identity_key)
        ->toBe(MailboxSenderIdentityService::keyForEmail($identities['alternate']))
        ->and($messages)->toHaveCount(2)
        ->and($first->fresh()->from_email)->toBe($identities['primary'])
        ->and($messages[1]->reply_to_message_id)->toBe($first->id)
        ->and($messages[1]->from_email)->toBe($identities['alternate'])
        ->and(FakeMailProvider::$store['sends'])->toHaveCount(2)
        ->and(FakeMailProvider::$store['sends'][0]['fromEmail'])->toBe($identities['primary'])
        ->and(FakeMailProvider::$store['sends'][1]['fromEmail'])->toBe($identities['alternate']);
});

it('blocks a campaign recipient without creating duplicate empty threads when no Zoho identity is active', function () {
    $this->travelTo(now()->setTime(10, 0));
    [, $mailbox, , $recipient] = launchedCampaign();
    configureCampaignZohoIdentities($mailbox);
    app(MailboxSenderIdentityService::class)->sync(
        $mailbox->fresh('connection'),
        [],
        supported: true,
    );

    $firstRun = sendDueCampaigns();
    $secondRun = sendDueCampaigns();

    expect($firstRun)->toBe(0)
        ->and($secondRun)->toBe(0)
        ->and(Thread::query()->where('campaign_recipient_id', $recipient->id)->count())->toBe(0)
        ->and(Message::query()->where('campaign_recipient_id', $recipient->id)->count())->toBe(0)
        ->and(SendAttempt::query()->where('campaign_recipient_id', $recipient->id)->count())->toBe(0)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::BlockedMailbox)
        ->and($recipient->fresh()->blocked_from_status)->toBe(RecipientStatus::Active->value)
        ->and($recipient->fresh()->blocked_at)->not->toBeNull()
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('defers a queued message when its snapshotted Zoho identity is no longer active', function () {
    $this->travelTo(now()->setTime(10, 0));
    [, $mailbox, , $recipient] = launchedCampaign();
    $identities = configureCampaignZohoIdentities($mailbox);
    Config::set('outboss.campaign_sending_enabled', true);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);

    $attempt = SendAttempt::query()->where('campaign_recipient_id', $recipient->id)->firstOrFail();
    expect($attempt->message->from_email)->toBe($identities['primary']);

    app(MailboxSenderIdentityService::class)->sync($mailbox->fresh('connection'), [
        new RemoteSenderIdentity(
            key: 'provider-alternate',
            emailAddress: $identities['alternate'],
            displayName: 'Partnerships',
            mode: 'alias',
        ),
    ], supported: true);

    app(SendCampaignMessage::class)->handle($attempt->id);

    expect($attempt->fresh()->status)->toBe(SendAttemptStatus::Deferred)
        ->and($attempt->fresh()->deferred_reason)->toBe('sender_identity_unavailable')
        ->and($attempt->fresh()->attempt_count)->toBe(0)
        ->and($attempt->fresh()->provider_phase)->toBeNull()
        ->and($attempt->message->fresh()->from_email)->toBe($identities['primary'])
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::BlockedMailbox)
        ->and($recipient->fresh()->blocked_at)->not->toBeNull()
        ->and(FakeMailProvider::$store['sends'] ?? [])->toBe([]);
});

it('does not send when the kill switch is off', function () {
    $this->travelTo(now()->setTime(10, 0));
    launchedCampaign();

    Config::set('outboss.campaign_sending_enabled', false);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0);
});

it('schedules a running experiment cohort without rollout configuration', function () {
    $this->travelTo(now()->setTime(10, 0));
    [$user, , $campaign] = launchedCampaign();
    $experiment = killSwitchExperimentCampaign($campaign, $user);

    Config::set('outboss.campaign_sending_enabled', true);

    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1)
        ->and(SendAttempt::query()->count())->toBe(1);
});

it('fails closed when only the experiment cohort points at a campaign', function () {
    $this->travelTo(now()->setTime(10, 0));
    [$user, , $campaign] = launchedCampaign();
    $experiment = MailExperiment::factory()->running()->create([
        'workspace_id' => $campaign->getAttribute('workspace_id'),
        'created_by_user_id' => $user->id,
    ]);
    MailExperimentCohort::factory()->create([
        'mail_experiment_id' => $experiment->id,
        'campaign_id' => $campaign->id,
        'key' => 'a',
    ]);
    $experiment->forceFill(['status' => 'paused'])->save();

    Config::set('outboss.campaign_sending_enabled', true);

    expect(app(ManagedMailCapability::class)->campaignRuntimeAllows($campaign))->toBeFalse();
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(0)
        ->and(SendAttempt::query()->count())->toBe(0);
});

it('leaves a queued experiment send retryable when the experiment is paused', function () {
    $this->travelTo(now()->setTime(10, 0));
    [$user, , $campaign] = launchedCampaign();
    $experiment = killSwitchExperimentCampaign($campaign, $user);

    Config::set('outboss.campaign_sending_enabled', true);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);

    $attempt = SendAttempt::query()->firstOrFail();
    expect($attempt->status)->toBe(SendAttemptStatus::Queued);

    $experiment->forceFill(['status' => 'paused'])->save();
    app(SendCampaignMessage::class)->handle($attempt->id);

    expect($attempt->fresh()->status)->toBe(SendAttemptStatus::Queued)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toHaveCount(0);
});

it('defers a queued send when the campaign is paused before the lease', function () {
    $this->travelTo(now()->setTime(10, 0));
    [, , $campaign] = launchedCampaign();

    Config::set('outboss.campaign_sending_enabled', true);
    app(ScheduleDueCampaignSends::class)->handle();
    $sendsBefore = count(FakeMailProvider::$store['sends'] ?? []);
    app(LaunchCampaignService::class)->pause($campaign->fresh());
    app(PublishOutboxEvents::class)->handle();

    $attempt = SendAttempt::query()->first();
    expect($attempt->status)->toBe(SendAttemptStatus::Deferred)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toHaveCount($sendsBefore);
});

it('stops later steps when a human reply commits before the lease', function () {
    $this->travelTo(now()->setTime(10, 0));
    [, $mailbox, $campaign, $recipient, $step] = launchedCampaign();

    SequenceStep::factory()->create([
        'sequence_version_id' => $step->sequence_version_id,
        'position' => 2,
        'title' => 'Follow-up',
        'delay_minutes' => 0,
        'send_as' => SequenceSendAs::Reply,
        'body_template' => 'Bumping this.',
    ]);

    Config::set('outboss.campaign_sending_enabled', true);
    app(ScheduleDueCampaignSends::class)->handle();
    $sendsBefore = count(FakeMailProvider::$store['sends'] ?? []);

    $thread = Thread::factory()->create([
        'user_id' => $campaign->user_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
    ]);
    $inbound = Message::factory()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'classification' => ThreadClassification::Human,
        'direction' => MessageDirection::Inbound,
    ]);
    app(ApplyInboundCampaignEffects::class)->handle($inbound);

    app(PublishOutboxEvents::class)->handle();

    expect($recipient->fresh()->status)->toBe(RecipientStatus::Replied)
        ->and(SendAttempt::query()->first()->status)->toBe(SendAttemptStatus::Cancelled)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toHaveCount($sendsBefore);
});

it('moves unknown sends to manual review and audits both resolutions', function () {
    $this->travelTo(now()->setTime(10, 0));
    launchedCampaign();
    FakeMailProvider::$timeoutSend = true;
    Config::set('outboss.campaign_sending_enabled', true);
    app(ScheduleDueCampaignSends::class)->handle();
    $attempt = SendAttempt::query()->first();
    app(SendCampaignMessage::class)->handle($attempt->id);

    expect($attempt->fresh()->status)->toBe(SendAttemptStatus::Unknown);

    FakeMailProvider::$timeoutSend = false;
    FakeMailProvider::$store['sends'][] = [
        'mailbox' => $attempt->mailbox->email_address,
        'subject' => $attempt->message->subject,
    ];
    app(ReconcileUnknownSend::class)->handle($attempt->id);
    expect($attempt->fresh()->status)->toBe(SendAttemptStatus::ManualReview);

    $this->artisan('outboss:sends:confirm-not-sent', [
        'attempt' => $attempt->id,
        '--operator' => 'david',
        '--evidence' => 'not in sent folder',
    ])->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(SendAttemptStatus::Deferred)
        ->and($attempt->resolutions()->count())->toBe(1);

    app(SendCampaignMessage::class)->handle($attempt->id);
    $attempt->fresh()->forceFill([
        'status' => SendAttemptStatus::ManualReview,
        'manual_review_cycle' => 2,
    ])->save();

    app(ResolveManualReviewSend::class)->handle(
        $attempt->fresh(),
        SendResolution::ConfirmedSent,
        'david',
        'found in sent',
    );

    expect($attempt->fresh()->status)->toBe(SendAttemptStatus::Sent)
        ->and($attempt->resolutions()->count())->toBe(2);
});

it('reconciles a lost provider response atomically and advances the campaign recipient', function () {
    $this->travelTo(now()->setTime(10, 0));
    [, $mailbox, $campaign, $recipient] = launchedCampaign();

    FakeMailProvider::$timeoutAfterSend = true;
    Config::set('outboss.campaign_sending_enabled', true);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);

    $attempt = SendAttempt::query()->firstOrFail();
    app(SendCampaignMessage::class)->handle($attempt->id);

    $unknown = $attempt->fresh();
    expect($unknown->status)->toBe(SendAttemptStatus::Unknown)
        ->and($recipient->fresh()->first_sent_at)->toBeNull()
        ->and($recipient->fresh()->send_lease_token)->not->toBeNull()
        ->and(FakeMailProvider::$store['sends'] ?? [])->toHaveCount(1);

    $send = FakeMailProvider::$store['sends'][0];
    $remote = FakeMailProvider::$store['messages'][$mailbox->email_address][$send['remoteMessageId']] ?? null;
    expect($remote)->toBeInstanceOf(RemoteMessage::class);

    $reconciler = Mockery::mock(MailTransport::class);
    $reconciler->shouldReceive('reconcileUnknownSend')
        ->once()
        ->andReturn(SendReconciliationResult::confirmedSent(
            new SendResult(
                remoteMessageId: $send['remoteMessageId'],
                remoteThreadId: $send['remoteThreadId'],
                internetMessageId: $send['internetMessageId'],
                phase: 'reconciled_sent',
            ),
            $remote,
        ));
    app()->instance(ProviderRegistry::class, new ProviderRegistry([
        MailProviderName::Gmail->value => $reconciler,
    ]));
    $connectionUsable = Mockery::mock(EnsureConnectionUsable::class);
    $connectionUsable->shouldReceive('handle')->andReturn($mailbox->connection);
    app()->instance(EnsureConnectionUsable::class, $connectionUsable);

    expect(app(ReconcileUnknownSend::class)->handle($attempt->id))->toBe(1);

    $resolvedRecipient = $recipient->fresh();
    $resolvedAttempt = $attempt->fresh();
    expect($resolvedAttempt->status)->toBe(SendAttemptStatus::Sent)
        ->and($resolvedAttempt->provider_phase)->toBe('reconciled_sent')
        ->and($resolvedAttempt->provider_accepted_at)->not->toBeNull()
        ->and($resolvedAttempt->sent_at)->not->toBeNull()
        ->and($resolvedRecipient->first_sent_at)->not->toBeNull()
        ->and($resolvedRecipient->last_sent_at)->not->toBeNull()
        ->and($resolvedRecipient->send_lease_token)->toBeNull()
        ->and($resolvedRecipient->send_lease_expires_at)->toBeNull()
        ->and($resolvedRecipient->status)->toBe(RecipientStatus::Completed)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Completed)
        ->and($attempt->message->fresh()->status)->toBe(MessageStatus::Sent);

    $firstSentAt = $resolvedRecipient->first_sent_at;
    $lastSentAt = $resolvedRecipient->last_sent_at;
    expect(app(ReconcileUnknownSend::class)->handle($attempt->id))->toBe(0)
        ->and($recipient->fresh()->first_sent_at)->toEqual($firstSentAt)
        ->and($recipient->fresh()->last_sent_at)->toEqual($lastSentAt)
        ->and($recipient->fresh()->send_lease_token)->toBeNull();
});

it('sends a standalone test preview without advancing recipients or recording opens', function () {
    [$user, $mailbox, $campaign, $recipient, $step] = launchedCampaign();
    $step->forceFill([
        'subject_template' => '{Question|Quick question|One question} about {{company}}',
        'body_template' => '{Hi|Hello|Hey} {{first_name}}',
        'subject_spintax_enabled' => true,
        'body_spintax_enabled' => true,
    ])->save();
    $preview = $this->actingAs($user)
        ->getJson(route('sequence-steps.preview', $step))
        ->assertOk()
        ->json();

    $this->actingAs($user)
        ->post(route('sequence-steps.test', $step), [
            'mailbox_id' => $mailbox->id,
            'to' => 'david@example.com',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertRedirect();

    $testMessage = SendAttempt::query()->where('kind', SendAttemptKind::Test)->firstOrFail()->message;

    expect($recipient->fresh()->first_sent_at)->toBeNull()
        ->and($testMessage->getAttribute('subject'))->toBe('[TEST] '.$preview['subject'])
        ->and($testMessage->getAttribute('body_text'))->toBe($preview['body'])
        ->and(SendAttempt::query()->where('kind', SendAttemptKind::Test)->count())->toBe(1);

    $message = SendAttempt::query()->where('kind', SendAttemptKind::Test)->first()->message;
    $token = (string) Str::uuid();
    $message->forceFill([
        'tracking_token' => $token,
        'campaign_recipient_id' => $recipient->id,
    ])->save();

    $this->get('/t/'.$token.'.gif')->assertOk();
    expect($recipient->fresh()->first_opened_at)->toBeNull();
});

it('uses the originating new-thread subject variation for a reply test send', function () {
    [$user, $mailbox, $campaign] = launchedCampaign();
    $intro = $campaign->fresh('publishedVersion.activeSteps')->publishedVersion->activeSteps->first();
    $intro->forceFill([
        'subject_template' => '{Question|Quick question|One question} about {{company}}',
        'subject_spintax_enabled' => true,
    ])->save();
    $reply = SequenceStep::factory()->create([
        'sequence_version_id' => $intro->sequence_version_id,
        'position' => 2,
        'title' => 'Follow-up',
        'delay_minutes' => 4320,
        'send_as' => SequenceSendAs::Reply,
        'subject_template' => null,
        'body_template' => 'Bumping this.',
    ]);
    $preview = new CampaignRecipient([
        'workspace_id' => $campaign->workspace_id,
        'first_name' => 'Jane',
        'company' => 'Acme',
        'email_address' => 'david@example.com',
    ]);
    $expected = app(RenderSequenceMessage::class)->handle(
        $intro->fresh(),
        $preview,
        test: true,
        recipientSeed: 'sample',
    );

    $this->actingAs($user)
        ->post(route('sequence-steps.test', $reply), [
            'mailbox_id' => $mailbox->id,
            'to' => 'david@example.com',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertRedirect();

    $message = SendAttempt::query()
        ->where('kind', SendAttemptKind::Test)
        ->latest('id')
        ->firstOrFail()
        ->message;

    expect($message->getAttribute('subject'))->toBe($expected['subject']);
});

it('sends malformed pre-feature spintax literally without blocking other campaigns', function () {
    $this->travelTo(now()->setTime(10, 0));
    [, , $invalidCampaign, , $invalidStep] = launchedCampaign();
    [, , $validCampaign, $validRecipient] = launchedCampaign();
    $invalidStep->forceFill(['body_template' => '{Hi|Hello'])->save();
    Config::set('outboss.campaign_sending_enabled', true);

    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(2)
        ->and($invalidCampaign->fresh()->status)->toBe(CampaignStatus::Active)
        ->and(Message::query()
            ->where('campaign_recipient_id', $invalidCampaign->recipients()->value('id'))
            ->value('body_text'))->toBe('{Hi|Hello')
        ->and(SendAttempt::query()->where('campaign_recipient_id', $validRecipient->id)->exists())->toBeTrue()
        ->and($validCampaign->fresh()->status)->toBe(CampaignStatus::Active);
});

it('skips a malformed enabled template without blocking other campaigns', function () {
    $this->travelTo(now()->setTime(10, 0));
    [, , $invalidCampaign, , $invalidStep] = launchedCampaign();
    [, , $validCampaign, $validRecipient] = launchedCampaign();
    $invalidStep->forceFill([
        'body_template' => '{Hi|Hello',
        'body_spintax_enabled' => true,
    ])->save();
    Config::set('outboss.campaign_sending_enabled', true);

    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1)
        ->and($invalidCampaign->fresh()->status)->toBe(CampaignStatus::Active)
        ->and(Message::query()
            ->where('campaign_recipient_id', $invalidCampaign->recipients()->value('id'))
            ->exists())->toBeFalse()
        ->and(SendAttempt::query()->where('campaign_recipient_id', $validRecipient->id)->exists())->toBeTrue()
        ->and($validCampaign->fresh()->status)->toBe(CampaignStatus::Active);
});

it('returns a body validation error instead of sending a malformed enabled template', function () {
    [$user, $mailbox, $campaign, , $step] = launchedCampaign();
    $step->forceFill(['body_template' => '{Hi|Hello', 'body_spintax_enabled' => true])->save();

    $this->actingAs($user)
        ->from(route('campaigns.show', $campaign))
        ->post(route('sequence-steps.test', $step), [
            'mailbox_id' => $mailbox->id,
            'to' => 'david@example.com',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertRedirect(route('campaigns.show', $campaign))
        ->assertSessionHasErrors([
            'body_template' => 'Email 1 body: Unclosed variation. Add a closing } or escape the opening brace as \\{.',
        ]);

    expect(SendAttempt::query()->where('kind', SendAttemptKind::Test)->exists())->toBeFalse();
});

it('clones a published sequence when a paused campaign is edited', function () {
    [$user, , $campaign, $recipient, $step] = launchedCampaign();
    $recipient->forceFill(['first_sent_at' => now()])->save();
    app(LaunchCampaignService::class)->pause($campaign->fresh());

    $this->actingAs($user)
        ->post(route('campaigns.steps.store', $campaign), ['title' => 'Bump'])
        ->assertRedirect();

    $campaign->refresh();
    expect($campaign->draft_sequence_version_id)->not->toBe($campaign->published_sequence_version_id)
        ->and($recipient->fresh()->sequence_version_id)->toBe($campaign->published_sequence_version_id)
        ->and($campaign->draftVersion->activeSteps)->toHaveCount(2);
});
