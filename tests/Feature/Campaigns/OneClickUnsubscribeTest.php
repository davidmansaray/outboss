<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\SuppressionReason;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\SendAttempt;
use App\Models\SuppressionEntry;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

it('unsubscribes on POST and leaves GET unchanged', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $first = Campaign::factory()->create(['user_id' => $user->id, 'business_days_only' => false]);
    $second = Campaign::factory()->create(['user_id' => $user->id, 'business_days_only' => false]);
    $first->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $second->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $token = (string) Str::uuid();

    foreach ([$first, $second] as $campaign) {
        $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
        CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'mailbox_id' => $mailbox->id,
            'email_address' => 'maya@stripe.com',
            'email_normalized' => 'maya@stripe.com',
            'email_domain' => 'stripe.com',
            'unsubscribe_token' => $campaign->is($first) ? $token : (string) Str::uuid(),
            'status' => RecipientStatus::Active,
            'next_sequence_step_id' => $step->id,
            'next_send_at' => now(),
        ]);
    }

    $recipient = CampaignRecipient::query()->where('unsubscribe_token', $token)->firstOrFail();
    SendAttempt::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $mailbox->workspace_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Queued,
    ]);

    $this->get(route('unsubscribe.show', $token))
        ->assertOk()
        ->assertSee('Confirm that you want to stop');

    expect(SuppressionEntry::query()->count())->toBe(0)
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Active);

    $this->post(route('unsubscribe.store', $token))
        ->assertOk()
        ->assertSee('You are unsubscribed');

    $repeat = $this->post(route('unsubscribe.store', $token))->assertOk();
    $unknown = $this->post(route('unsubscribe.store', (string) Str::uuid()))->assertOk();

    expect($repeat->getContent())->toBe($unknown->getContent())
        ->and(SuppressionEntry::query()->count())->toBe(1)
        ->and(SuppressionEntry::query()->first()->reason)->toBe(SuppressionReason::Unsubscribe)
        ->and(CampaignRecipient::query()->where('email_normalized', 'maya@stripe.com')->pluck('status')->all())
        ->each->toBe(RecipientStatus::Unsubscribed)
        ->and(SendAttempt::query()->where('campaign_recipient_id', $recipient->id)->value('status'))
        ->toBe(SendAttemptStatus::Cancelled);
});

it('adds list-unsubscribe headers on campaign sends and not on test sends', function () {
    $this->travelTo(now()->setTime(10, 0));
    [$user, $mailbox, $campaign, $recipient, $step] = launchedCampaign();
    Config::set('outboss.campaign_sending_enabled', true);

    sendDueCampaigns();

    expect(FakeMailProvider::$store['sends'][0]['list_unsubscribe'])->toContain('/unsub/')
        ->and(FakeMailProvider::$store['sends'][0]['list_unsubscribe_post'])->toBeTrue();

    $this->actingAs($user)
        ->post(route('sequence-steps.test', $step), [
            'mailbox_id' => $mailbox->id,
            'to' => 'david@example.com',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertRedirect();

    expect(FakeMailProvider::$store['sends'][1]['list_unsubscribe'] ?? null)->toBeNull();
});

it('skips a one-click unsubscribed address on the next scheduler run', function () {
    $this->travelTo(now()->setTime(10, 0));
    [, , , $recipient] = launchedCampaign();
    $this->post(route('unsubscribe.store', $recipient->unsubscribe_token))->assertOk();

    Config::set('outboss.campaign_sending_enabled', true);
    sendDueCampaigns();

    expect(SendAttempt::query()->where('kind', SendAttemptKind::Campaign)->count())->toBe(0)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toHaveCount(0);
});
