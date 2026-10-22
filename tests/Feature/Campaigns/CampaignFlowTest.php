<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('provides campaign summaries for the P0 operating view', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'email_address' => 'sender@outboss.test',
    ]);
    $campaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'name' => 'Series A founders',
        'status' => CampaignStatus::Active,
    ]);
    $campaign->mailboxes()->attach($mailbox, ['position' => 1]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Active,
        'first_sent_at' => now(),
    ]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Interested,
        'first_sent_at' => now(),
    ]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Removed,
    ]);

    $this->actingAs($user)
        ->get(route('campaigns.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('campaigns/Index')
            ->has('campaigns', 1)
            ->where('campaigns.0.name', 'Series A founders')
            ->where('campaigns.0.status', 'active')
            ->where('campaigns.0.people_count', 2)
            ->where('campaigns.0.steps_count', 1)
            ->where('campaigns.0.mailboxes_count', 1)
            ->where('campaigns.0.sender_emails', ['sender@outboss.test'])
            ->where('campaigns.0.senders_more', 0)
            ->where('campaigns.0.sent_count', 2)
            ->where('campaigns.0.replies_count', 1)
            ->where('campaigns.0.interested_count', 1)
            ->where('campaigns.0.queued_count', 0)
            ->where('campaigns.0.bounced_count', 0)
            ->where('campaigns.0.unsubscribed_count', 0));
});

it('hides attached disconnected mailboxes from campaign sending setup', function () {
    $user = User::factory()->create();
    $disconnected = Mailbox::factory()->create([
        'user_id' => $user->id,
        'status' => ConnectionStatus::Disconnected,
    ]);
    $connected = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $campaign->mailboxes()->attach($disconnected, ['position' => 1]);
    $campaign->mailboxes()->attach($connected, ['position' => 2]);

    $this->actingAs($user)
        ->get(route('campaigns.show', $campaign))
        ->assertInertia(fn (Assert $page) => $page
            ->where('campaign.mailboxes', [$connected->id])
            ->where('campaign.mailbox_emails', [$connected->email_address])
            ->has('availableMailboxes', 1)
            ->where('availableMailboxes.0.id', $connected->id)
            ->where('availableMailboxes.0.status', 'connected'));
});

it('does not allow a disconnected mailbox to be selected for a campaign', function () {
    $user = User::factory()->create();
    $disconnected = Mailbox::factory()->create([
        'user_id' => $user->id,
        'status' => ConnectionStatus::Disconnected,
    ]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patch(route('campaigns.update', $campaign), [
            'mailbox_ids' => [$disconnected->id],
        ])
        ->assertRedirect();

    expect($campaign->mailboxes()->count())->toBe(0);
});

it('creates a campaign and imports people but requires exact approval before launch', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('campaigns.store'), ['name' => 'Q3 founders'])
        ->assertRedirect();

    $campaign = Campaign::query()->first();
    expect($campaign->mailboxes()->pluck('mailboxes.id')->all())->toBe([$mailbox->id]);

    $this->actingAs($user)
        ->patch(route('campaigns.update', $campaign), [
            'send_mode' => 'rotate',
            'mailbox_ids' => [$mailbox->id],
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('campaigns.imports.preview', $campaign), [
            'source' => 'paste',
            'rows' => [
                ['email' => 'maya@stripe.com', 'name' => 'Maya Chen', 'company' => 'Stripe'],
                ['email' => 'bad', 'name' => 'Nope', 'company' => 'Acme'],
            ],
            'mapping' => ['email' => 'email', 'name' => 'name', 'company' => 'company'],
        ])
        ->assertRedirect();

    $import = $campaign->fresh()->imports()->first();
    expect($import->ready)->toBe(1)->and($import->invalid)->toBe(1);

    $this->actingAs($user)
        ->post(route('campaigns.imports.commit', [$campaign, $import]), [
            'expected_mapping_version' => $import->mapping_version,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('campaigns.launch', $campaign))
        ->assertRedirect()
        ->assertSessionHasErrors('campaign');

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft)
        ->and($campaign->recipients()->first()->status)->toBe(RecipientStatus::Queued)
        ->and($campaign->recipients()->first()->mailbox_id)->toBe($mailbox->id);
});

it('assigns recipients round-robin across campaign mailboxes', function () {
    $user = User::factory()->create();
    $first = Mailbox::factory()->create(['user_id' => $user->id]);
    $second = Mailbox::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user)->post(route('campaigns.store'), ['name' => 'Rotate'])->assertRedirect();
    $campaign = Campaign::query()->first();
    expect($campaign->mailboxes()->pluck('mailboxes.id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());

    $this->actingAs($user)
        ->patch(route('campaigns.update', $campaign), [
            'send_mode' => 'rotate',
            'mailbox_ids' => [$first->id, $second->id],
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('campaigns.imports.preview', $campaign), [
            'source' => 'paste',
            'rows' => [
                ['email' => 'one@acme.com', 'name' => 'One', 'company' => 'Acme'],
                ['email' => 'two@acme.com', 'name' => 'Two', 'company' => 'Acme'],
            ],
            'mapping' => ['email' => 'email', 'name' => 'name', 'company' => 'company'],
        ])
        ->assertRedirect();

    $import = $campaign->fresh()->imports()->first();
    $this->actingAs($user)
        ->post(route('campaigns.imports.commit', [$campaign, $import]), [
            'expected_mapping_version' => $import->mapping_version,
        ])
        ->assertRedirect();

    $mailboxes = $campaign->recipients()->orderBy('id')->pluck('mailbox_id')->all();
    expect($mailboxes)->toBe([$first->id, $second->id]);
});

it('rejects launch without people or mailboxes', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('campaigns.store'), ['name' => 'Empty'])->assertRedirect();
    $campaign = Campaign::query()->first();

    $this->actingAs($user)
        ->post(route('campaigns.launch', $campaign))
        ->assertSessionHasErrors();
});
