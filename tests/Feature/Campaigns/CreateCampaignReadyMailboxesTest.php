<?php

use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Models\Mailbox;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('attaches every ready workspace inbox when a campaign is created', function () {
    $owner = User::factory()->create();
    $first = Mailbox::factory()->create(['user_id' => $owner->id]);
    $second = Mailbox::factory()->create(['user_id' => $owner->id]);

    $campaign = app(CreateCampaignService::class)->handle($owner, 'Ready senders');

    expect($campaign->mailboxes()->pluck('mailboxes.id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

it('does not attach disconnected, paused, or foreign inboxes', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ready = Mailbox::factory()->create(['user_id' => $owner->id]);
    Mailbox::factory()->create([
        'user_id' => $owner->id,
        'status' => ConnectionStatus::Disconnected,
    ]);
    Mailbox::factory()->create([
        'user_id' => $owner->id,
        'status' => ConnectionStatus::NeedsReconnect,
    ]);
    Mailbox::factory()->create([
        'user_id' => $owner->id,
        'sending_paused_at' => now(),
    ]);
    Mailbox::factory()->create(['user_id' => $other->id]);

    $campaign = app(CreateCampaignService::class)->handle($owner, 'Filtered senders');

    expect($campaign->mailboxes()->pluck('mailboxes.id')->all())->toBe([$ready->id]);
});

it('creates a campaign with no senders when the workspace has no ready inbox', function () {
    $owner = User::factory()->create();

    $campaign = app(CreateCampaignService::class)->handle($owner, 'No senders yet');

    expect($campaign->mailboxes()->count())->toBe(0);
});

it('exposes attached ready inboxes on the campaign sending page after create', function () {
    $owner = User::factory()->create();
    $mailbox = Mailbox::factory()->create([
        'user_id' => $owner->id,
        'email_address' => 'founders@outboss.test',
        'display_name' => 'Outboss Founders',
    ]);

    $this->actingAs($owner)
        ->post(route('campaigns.store'), ['name' => 'Visible senders'])
        ->assertRedirect();

    $campaign = $owner->campaigns()->sole();

    $this->actingAs($owner)
        ->get(route('campaigns.show', $campaign))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('campaign.mailboxes', [$mailbox->id])
            ->where('campaign.mailbox_emails', ['founders@outboss.test'])
            ->has('availableMailboxes', 1)
            ->where('availableMailboxes.0.id', $mailbox->id));
});
