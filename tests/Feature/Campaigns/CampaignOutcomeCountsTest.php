<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\SendResolution;
use App\Domain\Mail\Services\ResolveManualReviewSend;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SendAttempt;
use App\Models\Thread;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('exposes the same workspace-scoped outcome counts on the campaign list and show page', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $campaign->mailboxes()->attach($mailbox, ['position' => 1]);

    $make = function (RecipientStatus $status, ?string $sentAt = null) use ($campaign, $mailbox): CampaignRecipient {
        return CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'mailbox_id' => $mailbox->id,
            'status' => $status,
            'first_sent_at' => $sentAt,
        ]);
    };

    $make(RecipientStatus::Queued);
    $make(RecipientStatus::Active);
    $make(RecipientStatus::Paused);
    $make(RecipientStatus::BlockedMailbox);
    $make(RecipientStatus::Active, now()->toDateTimeString());
    $make(RecipientStatus::Replied, now()->toDateTimeString());
    $make(RecipientStatus::Interested, now()->toDateTimeString());
    $make(RecipientStatus::NotNow, now()->toDateTimeString());
    $make(RecipientStatus::WrongPerson, now()->toDateTimeString());
    $make(RecipientStatus::Bounced, now()->toDateTimeString());
    $make(RecipientStatus::Unsubscribed, now()->toDateTimeString());
    $make(RecipientStatus::Removed, now()->toDateTimeString());

    $other = User::factory()->create();
    $otherMailbox = Mailbox::factory()->create(['user_id' => $other->id]);
    $otherCampaign = Campaign::factory()->create(['user_id' => $other->id]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $otherCampaign->id,
        'mailbox_id' => $otherMailbox->id,
        'status' => RecipientStatus::Bounced,
        'first_sent_at' => now(),
    ]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $otherCampaign->id,
        'mailbox_id' => $otherMailbox->id,
        'status' => RecipientStatus::Queued,
    ]);

    $expected = [
        'queued' => 4,
        'sent' => 8,
        'replied' => 4,
        'interested' => 1,
        'bounced' => 1,
        'unsubscribed' => 1,
    ];

    $this->actingAs($user)
        ->get(route('campaigns.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('campaigns/Index')
            ->where('campaigns.0.queued_count', $expected['queued'])
            ->where('campaigns.0.sent_count', $expected['sent'])
            ->where('campaigns.0.replies_count', $expected['replied'])
            ->where('campaigns.0.interested_count', $expected['interested'])
            ->where('campaigns.0.bounced_count', $expected['bounced'])
            ->where('campaigns.0.unsubscribed_count', $expected['unsubscribed']));

    $this->actingAs($user)
        ->get(route('campaigns.show', $campaign))
        ->assertInertia(fn (Assert $page) => $page
            ->component('campaigns/Show')
            ->where('outcomes.queued', $expected['queued'])
            ->where('outcomes.sent', $expected['sent'])
            ->where('outcomes.replied', $expected['replied'])
            ->where('outcomes.interested', $expected['interested'])
            ->where('outcomes.bounced', $expected['bounced'])
            ->where('outcomes.unsubscribed', $expected['unsubscribed'])
            ->where('outcomes.needs_review', 0));
});

it('exposes manual-review attempts as needs_review until they are resolved', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Active,
        'first_sent_at' => now(),
    ]);
    $thread = Thread::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $mailbox->workspace_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
    ]);
    $message = Message::factory()->outbound()->create([
        'workspace_id' => $mailbox->workspace_id,
        'mailbox_id' => $mailbox->id,
        'thread_id' => $thread->id,
        'campaign_recipient_id' => $recipient->id,
    ]);
    $attempt = SendAttempt::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $mailbox->workspace_id,
        'mailbox_id' => $mailbox->id,
        'message_id' => $message->id,
        'campaign_recipient_id' => $recipient->id,
        'status' => SendAttemptStatus::ManualReview,
        'kind' => SendAttemptKind::Campaign,
    ]);

    $this->actingAs($user)
        ->get(route('campaigns.show', $campaign))
        ->assertInertia(fn (Assert $page) => $page->where('outcomes.needs_review', 1));

    app(ResolveManualReviewSend::class)->handle(
        $attempt,
        SendResolution::ConfirmedSent,
        'tester',
        'found in Sent',
    );

    $this->actingAs($user)
        ->get(route('campaigns.show', $campaign))
        ->assertInertia(fn (Assert $page) => $page->where('outcomes.needs_review', 0));
});

it('does not count a recipient with a send history as queued', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Paused,
        'first_sent_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('campaigns.show', $campaign))
        ->assertInertia(fn (Assert $page) => $page
            ->where('outcomes.queued', 0)
            ->where('outcomes.sent', 1));
});
