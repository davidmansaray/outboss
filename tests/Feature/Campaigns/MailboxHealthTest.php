<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SendMode;
use App\Domain\Campaigns\Services\CampaignLaunchReadiness;
use App\Domain\Mail\Enums\BounceSubtype;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Domain\Mail\Enums\MailboxSendingPausedReason;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Services\ApplyInboundCampaignEffects;
use App\Domain\Mail\Services\EvaluateMailboxSendingHealth;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;
use App\Models\Message;
use App\Models\SendAttempt;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\Config;

function seedHardBounce(Mailbox $mailbox, CampaignRecipient $recipient): Thread
{
    $thread = Thread::factory()->create([
        'user_id' => $mailbox->user_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
    ]);
    $message = Message::factory()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'direction' => MessageDirection::Inbound,
        'classification' => ThreadClassification::Bounce,
        'bounce_subtype' => BounceSubtype::Hard,
        'from_email' => $recipient->email_address,
    ]);

    app(ApplyInboundCampaignEffects::class)->handle($message, isNewMessage: true, allowEmailFallback: true);

    return $thread->fresh();
}

it('pauses a mailbox after the hard-bounce threshold and keeps the other mailbox sending', function () {
    $this->travelTo(now()->setTime(10, 0));
    Config::set('outboss.health.hard_bounce_threshold', 3);
    $user = User::factory()->create();
    $unhealthy = Mailbox::factory()->create(['user_id' => $user->id]);
    $healthy = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'business_days_only' => false,
        'send_mode' => SendMode::Rotate,
    ]);
    $campaign->mailboxes()->sync([
        $unhealthy->id => ['position' => 1],
        $healthy->id => ['position' => 2],
    ]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();

    $unhealthyRecipients = [];
    foreach (range(1, 3) as $i) {
        $unhealthyRecipients[] = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'mailbox_id' => $unhealthy->id,
            'next_sequence_step_id' => $step->id,
            'status' => RecipientStatus::Active,
            'email_address' => "bounce-{$i}@acme.test",
            'email_normalized' => "bounce-{$i}@acme.test",
            'email_domain' => 'acme.test',
        ]);
    }
    $healthyRecipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $healthy->id,
        'next_sequence_step_id' => $step->id,
        'status' => RecipientStatus::Queued,
        'email_address' => 'ok@other.test',
        'email_normalized' => 'ok@other.test',
        'email_domain' => 'other.test',
    ]);

    foreach ($unhealthyRecipients as $recipient) {
        seedHardBounce($unhealthy->fresh(), $recipient);
    }

    expect($unhealthy->fresh()->sending_paused_at)->not->toBeNull()
        ->and($unhealthy->fresh()->sending_paused_reason)->toBe(MailboxSendingPausedReason::BounceThreshold->value)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Draft);

    $campaign->forceFill(['status' => CampaignStatus::Active])->save();
    $healthyRecipient->forceFill(['status' => RecipientStatus::Active, 'next_send_at' => now()])->save();
    Config::set('outboss.campaign_sending_enabled', true);
    sendDueCampaigns();

    expect(SendAttempt::query()->where('mailbox_id', $unhealthy->id)->count())->toBe(0)
        ->and(SendAttempt::query()->where('mailbox_id', $healthy->id)->where('status', SendAttemptStatus::Sent)->count())->toBe(1);
});

it('pauses a mailbox after the provider-error threshold', function () {
    Config::set('outboss.health.provider_error_threshold', 5);
    $mailbox = Mailbox::factory()->create();
    $domain = ManagedMailDomain::factory()->create([
        'workspace_id' => $mailbox->workspace_id,
        'created_by_user_id' => $mailbox->user_id,
    ]);
    $account = ManagedMailboxAccount::factory()->create([
        'managed_mail_domain_id' => $domain->id,
        'mailbox_id' => $mailbox->id,
    ]);

    foreach (range(1, 5) as $i) {
        SendAttempt::factory()->create([
            'user_id' => $mailbox->user_id,
            'workspace_id' => $mailbox->workspace_id,
            'mailbox_id' => $mailbox->id,
            'kind' => SendAttemptKind::Campaign,
            'status' => SendAttemptStatus::Unknown,
            'error_code' => 'provider_timeout',
        ]);
    }

    app(EvaluateMailboxSendingHealth::class)->handle($mailbox->fresh());

    $pauseEvent = $account->events()->where('event', 'mailbox_sending_paused')->firstOrFail();
    expect($mailbox->fresh()->sending_paused_reason)->toBe(MailboxSendingPausedReason::ProviderErrorThreshold->value)
        ->and($account->events()->where('event', 'mailbox_sending_paused')->count())->toBe(1)
        ->and($pauseEvent->safe_metadata['reason'])
        ->toBe(MailboxSendingPausedReason::ProviderErrorThreshold->value);
});

it('ignores health events outside the window', function () {
    Config::set('outboss.health.window_hours', 24);
    Config::set('outboss.health.provider_error_threshold', 5);
    $mailbox = Mailbox::factory()->create();

    foreach (range(1, 5) as $i) {
        $attempt = SendAttempt::factory()->create([
            'user_id' => $mailbox->user_id,
            'workspace_id' => $mailbox->workspace_id,
            'mailbox_id' => $mailbox->id,
            'kind' => SendAttemptKind::Campaign,
            'status' => SendAttemptStatus::Unknown,
            'error_code' => 'provider_timeout',
        ]);
        $attempt->forceFill(['updated_at' => now()->subHours(30)])->save();
    }

    app(EvaluateMailboxSendingHealth::class)->handle($mailbox->fresh());

    expect($mailbox->fresh()->sending_paused_at)->toBeNull();
});

it('blocks launch for paused and reconnecting mailboxes', function () {
    $user = User::factory()->create();
    $paused = Mailbox::factory()->create([
        'user_id' => $user->id,
        'sending_paused_at' => now(),
        'sending_paused_reason' => MailboxSendingPausedReason::BounceThreshold->value,
    ]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $campaign->mailboxes()->sync([$paused->id => ['position' => 1]]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $paused->id,
        'status' => RecipientStatus::Queued,
    ]);

    $ids = collect(app(CampaignLaunchReadiness::class)->blockers($campaign->fresh()))->pluck('id');
    expect($ids)->toContain('mailbox_sending_paused');

    $paused->forceFill([
        'sending_paused_at' => null,
        'sending_paused_reason' => null,
        'status' => ConnectionStatus::NeedsReconnect,
    ])->save();

    expect(collect(app(CampaignLaunchReadiness::class)->blockers($campaign->fresh()))->pluck('id'))
        ->toContain('mailbox_needs_reconnect');
});

it('resumes sending from the mailbox endpoint', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'sending_paused_at' => now(),
        'sending_paused_reason' => MailboxSendingPausedReason::BounceThreshold->value,
    ]);
    $domain = ManagedMailDomain::factory()->create([
        'workspace_id' => $mailbox->workspace_id,
        'created_by_user_id' => $user->id,
    ]);
    $account = ManagedMailboxAccount::factory()->create([
        'managed_mail_domain_id' => $domain->id,
        'mailbox_id' => $mailbox->id,
    ]);

    $this->actingAs($user)
        ->post(route('mailboxes.resume-sending', $mailbox))
        ->assertRedirect();

    expect($mailbox->fresh()->sending_paused_at)->toBeNull()
        ->and($mailbox->fresh()->sending_paused_reason)->toBeNull()
        ->and($account->events()->where('event', 'mailbox_sending_resumed')->count())->toBe(1)
        ->and($account->events()->where('event', 'mailbox_sending_resumed')->value('actor_user_id'))->toBe($user->id);
});

it('does not count a corrected false bounce toward the hard-bounce threshold', function () {
    Config::set('outboss.health.hard_bounce_threshold', 3);
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    $recipients = [];
    foreach (range(1, 3) as $i) {
        $recipients[] = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'mailbox_id' => $mailbox->id,
            'status' => RecipientStatus::Active,
            'email_address' => "bounce-{$i}@acme.test",
            'email_normalized' => "bounce-{$i}@acme.test",
        ]);
    }

    seedHardBounce($mailbox->fresh(), $recipients[0]);
    seedHardBounce($mailbox->fresh(), $recipients[1]);

    $falseThread = Thread::factory()->create([
        'user_id' => $user->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipients[2]->id,
    ]);
    Message::factory()->create([
        'thread_id' => $falseThread->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipients[2]->id,
        'direction' => MessageDirection::Inbound,
        'classification' => ThreadClassification::Bounce,
        'bounce_subtype' => BounceSubtype::Hard,
        'from_email' => $recipients[2]->email_address,
    ]);

    $this->actingAs($user)
        ->post(route('threads.classify', $falseThread), ['action' => 'replied'])
        ->assertRedirect();

    app(EvaluateMailboxSendingHealth::class)->handle($mailbox->fresh());

    expect($mailbox->fresh()->sending_paused_at)->toBeNull();
});

it('keeps an already-paused mailbox paused after a false bounce is corrected', function () {
    Config::set('outboss.health.hard_bounce_threshold', 3);
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    $threads = [];
    foreach (range(1, 3) as $i) {
        $recipient = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'mailbox_id' => $mailbox->id,
            'status' => RecipientStatus::Active,
            'email_address' => "trip-{$i}@acme.test",
            'email_normalized' => "trip-{$i}@acme.test",
        ]);
        $threads[] = seedHardBounce($mailbox->fresh(), $recipient);
    }

    expect($mailbox->fresh()->sending_paused_at)->not->toBeNull();

    $this->actingAs($user)
        ->post(route('threads.classify', $threads[2]), ['action' => 'replied'])
        ->assertRedirect();

    expect($mailbox->fresh()->sending_paused_at)->not->toBeNull();

    $this->actingAs($user)
        ->post(route('mailboxes.resume-sending', $mailbox))
        ->assertRedirect();

    expect($mailbox->fresh()->sending_paused_at)->toBeNull();
});
