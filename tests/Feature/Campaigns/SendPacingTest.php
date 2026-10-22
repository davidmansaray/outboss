<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SendMode;
use App\Domain\Campaigns\Services\AcquireRecipientSendLease;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Domain\Campaigns\Services\ScheduleDueCampaignSends;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\OutboxEvent;
use App\Models\SendAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * @return array{0: User, 1: Mailbox, 2: Campaign, 3: list<CampaignRecipient>}
 */
function pacedCampaign(int $people, int $cap = 50): array
{
    FakeMailProvider::reset();
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'daily_send_cap' => $cap,
    ]);
    $campaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'business_days_only' => false,
        'send_mode' => SendMode::Pin,
    ]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    $recipients = [];

    for ($i = 0; $i < $people; $i++) {
        $email = sprintf('person-%d@stripe.com', $i);
        $recipients[] = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'mailbox_id' => $mailbox->id,
            'sequence_version_id' => $campaign->draft_sequence_version_id,
            'next_sequence_step_id' => $step->id,
            'status' => RecipientStatus::Queued,
            'email_address' => $email,
            'email_normalized' => $email,
            'email_domain' => 'stripe.com',
            'first_name' => 'Person',
            'company' => 'Stripe',
        ]);
    }

    app(LaunchCampaignService::class)->handle($campaign->fresh(), $user);

    return [$user, $mailbox, $campaign->fresh(), $recipients];
}

it('sends only up to the mailbox daily cap and bumps the rest to tomorrow', function () {
    $this->travelTo(now()->utc()->setTime(10, 0));
    [, $mailbox, , $recipients] = pacedCampaign(3, cap: 2);

    sendDueCampaigns();

    $sent = SendAttempt::query()->where('kind', SendAttemptKind::Campaign)->where('status', SendAttemptStatus::Sent)->count();
    $bumped = collect($recipients)->first(
        fn (CampaignRecipient $recipient) => $recipient->fresh()->first_sent_at === null,
    );

    expect($sent)->toBe(2)
        ->and($bumped)->not->toBeNull()
        ->and($bumped->fresh()->next_send_at?->greaterThanOrEqualTo(now()->utc()->addDay()->startOfDay()))->toBeTrue()
        ->and(FakeMailProvider::$store['sends'])->toHaveCount(2);
});

it('spaces campaign outbox events for one mailbox', function () {
    $this->travelTo(now()->utc()->setTime(10, 0));
    Config::set('outboss.sending.spacing_min_seconds', 45);
    Config::set('outboss.sending.spacing_max_seconds', 45);
    [, $mailbox] = pacedCampaign(3, cap: 50);

    Config::set('outboss.campaign_sending_enabled', true);
    app(ScheduleDueCampaignSends::class)->handle();

    $times = OutboxEvent::query()
        ->orderBy('available_at')
        ->pluck('available_at')
        ->map(fn ($time) => $time->getTimestamp())
        ->all();

    expect($times)->toHaveCount(3)
        ->and($times[1] - $times[0])->toBe(45)
        ->and($times[2] - $times[1])->toBe(45)
        ->and($mailbox->fresh()->next_send_slot_at)->not->toBeNull();
});

it('defers a seventh same-domain send when the hourly limit is 6', function () {
    $this->travelTo(now()->utc()->setTime(10, 0));
    Config::set('outboss.sending.domain_hourly_limit', 6);
    [, , , $recipients] = pacedCampaign(7, cap: 50);

    sendDueCampaigns();

    $sent = SendAttempt::query()->where('status', SendAttemptStatus::Sent)->count();
    $waiting = collect($recipients)->first(
        fn (CampaignRecipient $recipient) => $recipient->fresh()->first_sent_at === null,
    );

    expect($sent)->toBe(6)
        ->and($waiting?->fresh()->next_send_at?->greaterThan(now()->addMinutes(50)))->toBeTrue();
});

it('defers a queued attempt at lease time when the cap is already consumed', function () {
    $this->travelTo(now()->utc()->setTime(10, 0));
    [, $mailbox, , $recipients] = pacedCampaign(1, cap: 1);
    Config::set('outboss.campaign_sending_enabled', true);
    app(ScheduleDueCampaignSends::class)->handle();

    $attempt = SendAttempt::query()->firstOrFail();
    SendAttempt::factory()->create([
        'user_id' => $mailbox->user_id,
        'workspace_id' => $mailbox->workspace_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipients[0]->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Sent,
        'lease_acquired_at' => now(),
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $leased = app(AcquireRecipientSendLease::class)->handle($attempt->fresh());

    expect($leased)->toBeNull()
        ->and($attempt->fresh()->status)->toBe(SendAttemptStatus::Deferred)
        ->and($attempt->fresh()->deferred_reason)->toBe('daily_cap_reached');
});

it('lets a test send ignore the campaign daily cap', function () {
    $this->travelTo(now()->utc()->setTime(10, 0));
    [$user, $mailbox, $campaign] = pacedCampaign(1, cap: 1);
    SendAttempt::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $mailbox->workspace_id,
        'mailbox_id' => $mailbox->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Sent,
        'lease_acquired_at' => now(),
    ]);

    $step = $campaign->fresh('publishedVersion.activeSteps')->publishedVersion->activeSteps->first();

    $this->actingAs($user)
        ->post(route('sequence-steps.test', $step), [
            'mailbox_id' => $mailbox->id,
            'to' => 'david@example.com',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertRedirect();

    expect(SendAttempt::query()->where('kind', SendAttemptKind::Test)->count())->toBe(1);
});
