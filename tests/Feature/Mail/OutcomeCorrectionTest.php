<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Services\ScheduleDueCampaignSends;
use App\Domain\Campaigns\Services\UnsubscribeRecipient;
use App\Domain\Mail\Enums\BounceSubtype;
use App\Domain\Mail\Enums\MessageDirection;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\SuppressionReason;
use App\Domain\Mail\Enums\ThreadClassification;
use App\Domain\Mail\Enums\ThreadLocalState;
use App\Domain\Mail\Services\ApplyInboundCampaignEffects;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Person;
use App\Models\SendAttempt;
use App\Models\SuppressionEntry;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function correctionThread(User $user, Mailbox $mailbox, Campaign $campaign, RecipientStatus $status = RecipientStatus::Active): Thread
{
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => $status,
        'next_send_at' => $status === RecipientStatus::Active ? now()->addDay() : null,
        'first_sent_at' => now(),
    ]);

    $thread = Thread::factory()->create([
        'user_id' => $user->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'classification' => ThreadClassification::Human,
        'local_state' => ThreadLocalState::NeedsAction,
        'participant_email' => $recipient->email_address,
    ]);

    Message::factory()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'direction' => MessageDirection::Inbound,
        'classification' => ThreadClassification::Human,
        'from_email' => $recipient->email_address,
    ]);

    return $thread->fresh('recipient');
}

it('corrects a replied recipient to interested from the unibox', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $thread = correctionThread($user, $mailbox, $campaign, RecipientStatus::Replied);
    $thread->recipient->forceFill([
        'stopped_at' => now(),
        'stop_reason' => 'human_reply',
        'replied_at' => now()->subMinute(),
        'next_send_at' => null,
    ])->save();

    $this->actingAs($user)
        ->post(route('threads.classify', $thread), ['action' => 'interested'])
        ->assertRedirect();

    expect($thread->recipient->fresh()->status)->toBe(RecipientStatus::Interested)
        ->and($thread->recipient->fresh()->stop_reason)->toBe('interested')
        ->and($thread->recipient->fresh()->next_send_at)->toBeNull()
        ->and($thread->recipient->fresh()->replied_at)->not->toBeNull();
});

it('round-trips reply-family corrections without resuming sending', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $thread = correctionThread($user, $mailbox, $campaign, RecipientStatus::Replied);
    $recipient = $thread->recipient;
    $repliedAt = now()->subMinutes(5);
    $recipient->forceFill([
        'stopped_at' => now()->subMinutes(5),
        'stop_reason' => 'human_reply',
        'replied_at' => $repliedAt,
        'next_send_at' => null,
    ])->save();
    $attempt = SendAttempt::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $mailbox->workspace_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'status' => SendAttemptStatus::Cancelled,
    ]);

    $this->actingAs($user)
        ->post(route('threads.classify', $thread), ['action' => 'interested'])
        ->assertRedirect();
    $this->actingAs($user)
        ->post(route('threads.classify', $thread), ['action' => 'not_now'])
        ->assertRedirect();

    $fresh = $recipient->fresh();
    expect($fresh->status)->toBe(RecipientStatus::NotNow)
        ->and($fresh->stop_reason)->toBe('not_now')
        ->and($fresh->next_send_at)->toBeNull()
        ->and($fresh->replied_at)->not->toBeNull()
        ->and($fresh->replied_at?->timestamp)->toBe($repliedAt->timestamp)
        ->and($attempt->fresh()->status)->toBe(SendAttemptStatus::Cancelled);

    app(ScheduleDueCampaignSends::class)->handle();

    expect(SendAttempt::query()
        ->where('campaign_recipient_id', $recipient->id)
        ->where('status', '!=', SendAttemptStatus::Cancelled)
        ->count())->toBe(0);
});

it('corrects a false hard bounce and removes only that suppression', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Active,
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
        'next_send_at' => now()->addDay(),
    ]);
    $thread = Thread::factory()->create([
        'user_id' => $user->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'remote_thread_id' => 'false-bounce',
    ]);
    $bounce = Message::factory()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'direction' => MessageDirection::Inbound,
        'classification' => ThreadClassification::Bounce,
        'bounce_subtype' => BounceSubtype::Hard,
        'from_email' => 'mailer-daemon@example.com',
        'received_at' => now(),
    ]);

    app(ApplyInboundCampaignEffects::class)->handle($bounce);

    expect($recipient->fresh()->status)->toBe(RecipientStatus::Bounced)
        ->and(SuppressionEntry::query()->where('email_normalized', 'maya@stripe.com')->count())->toBe(1);

    SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'other-manual@stripe.com',
        'reason' => SuppressionReason::Manual,
    ]);

    $this->actingAs($user)
        ->post(route('threads.classify', $thread->fresh()), ['action' => 'replied'])
        ->assertRedirect();

    $freshRecipient = $recipient->fresh();
    $freshThread = $thread->fresh();
    $freshBounce = $bounce->fresh();

    expect($freshRecipient->status)->toBe(RecipientStatus::Replied)
        ->and($freshRecipient->next_send_at)->toBeNull()
        ->and($freshThread->classification)->toBe(ThreadClassification::Human)
        ->and($freshThread->local_state)->toBe(ThreadLocalState::Done)
        ->and($freshBounce->classification)->toBe(ThreadClassification::Human)
        ->and($freshBounce->bounce_subtype)->toBeNull()
        ->and(SuppressionEntry::query()
            ->where('workspace_id', $user->currentWorkspace()->id)
            ->where('reason', SuppressionReason::HardBounce)
            ->count())->toBe(0)
        ->and(SuppressionEntry::query()
            ->where('reason', SuppressionReason::Manual)
            ->where('email_normalized', 'other-manual@stripe.com')
            ->count())->toBe(1);
});

it('keeps an unsubscribe suppression when the outcome is corrected', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $thread = correctionThread($user, $mailbox, $campaign, RecipientStatus::Unsubscribed);
    $thread->forceFill([
        'classification' => ThreadClassification::Unsubscribe,
        'local_state' => ThreadLocalState::Problem,
    ])->save();
    $thread->recipient->forceFill([
        'stop_reason' => 'unsubscribe',
        'stopped_at' => now(),
        'next_send_at' => null,
    ])->save();
    SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->currentWorkspace()->id,
        'email_address' => $thread->recipient->email_address,
        'email_normalized' => $thread->recipient->email_normalized,
        'reason' => SuppressionReason::Unsubscribe,
        'source_message_id' => $thread->messages()->first()->id,
    ]);

    $this->actingAs($user)
        ->from(route('unibox.index'))
        ->post(route('threads.classify', $thread), ['action' => 'replied'])
        ->assertRedirect()
        ->assertSessionHas(
            'success',
            'This person stays suppressed from the earlier unsubscribe — manage suppressions in Settings.',
        );

    expect($thread->recipient->fresh()->status)->toBe(RecipientStatus::Replied)
        ->and(SuppressionEntry::query()
            ->where('email_normalized', $thread->recipient->email_normalized)
            ->where('reason', SuppressionReason::Unsubscribe)
            ->count())->toBe(1);
});

it('does not let a replayed automatic bounce overwrite a correction', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $thread = correctionThread($user, $mailbox, $campaign, RecipientStatus::Replied);
    $thread->recipient->forceFill([
        'stop_reason' => 'human_reply',
        'stopped_at' => now(),
        'replied_at' => now(),
        'next_send_at' => null,
    ])->save();

    $bounce = Message::factory()->create([
        'thread_id' => $thread->id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $thread->campaign_recipient_id,
        'direction' => MessageDirection::Inbound,
        'classification' => ThreadClassification::Bounce,
        'bounce_subtype' => BounceSubtype::Hard,
        'from_email' => 'mailer-daemon@example.com',
        'received_at' => now()->subMinute(),
    ]);

    app(ApplyInboundCampaignEffects::class)->handle($bounce, isNewMessage: false);

    expect($thread->recipient->fresh()->status)->toBe(RecipientStatus::Replied);
});

it('does not let one-click unsubscribe overwrite a later correction', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $token = (string) Str::uuid();
    $thread = correctionThread($user, $mailbox, $campaign, RecipientStatus::Interested);
    $thread->recipient->forceFill([
        'unsubscribe_token' => $token,
        'stop_reason' => 'interested',
        'stopped_at' => now(),
        'next_send_at' => null,
    ])->save();

    $other = Campaign::factory()->create(['user_id' => $user->id]);
    $otherRecipient = CampaignRecipient::factory()->create([
        'campaign_id' => $other->id,
        'mailbox_id' => $mailbox->id,
        'email_address' => $thread->recipient->email_address,
        'email_normalized' => $thread->recipient->email_normalized,
        'status' => RecipientStatus::Active,
        'unsubscribe_token' => (string) Str::uuid(),
        'next_send_at' => now()->addDay(),
    ]);

    app(UnsubscribeRecipient::class)->handle($token);

    expect($thread->recipient->fresh()->status)->toBe(RecipientStatus::Interested)
        ->and($otherRecipient->fresh()->status)->toBe(RecipientStatus::Unsubscribed);
});

it('refuses to correct a removed recipient', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $thread = correctionThread($user, $mailbox, $campaign, RecipientStatus::Removed);
    $thread->recipient->forceFill([
        'removed_at' => now(),
        'stopped_at' => now(),
        'stop_reason' => 'removed',
        'next_send_at' => null,
    ])->save();

    $this->actingAs($user)
        ->post(route('threads.classify', $thread), ['action' => 'interested'])
        ->assertRedirect();

    expect($thread->recipient->fresh()->status)->toBe(RecipientStatus::Removed)
        ->and($thread->recipient->fresh()->stop_reason)->toBe('removed');
});

it('updates the registry reply label after a correction', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id, 'name' => 'Founders']);
    $person = Person::factory()->create([
        'workspace_id' => $user->currentWorkspace()->id,
        'full_name' => 'Maya Chen',
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
    ]);
    $thread = correctionThread($user, $mailbox, $campaign, RecipientStatus::Replied);
    $thread->recipient->forceFill([
        'person_id' => $person->id,
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
        'full_name' => 'Maya Chen',
        'stop_reason' => 'human_reply',
        'stopped_at' => now(),
        'replied_at' => now(),
        'next_send_at' => null,
    ])->save();

    $this->actingAs($user)
        ->get(route('prospects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('people.0.reply', 'Replied')
            ->where('people.0.campaigns.0.status', 'replied'));

    $this->actingAs($user)
        ->post(route('threads.classify', $thread), ['action' => 'interested'])
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('prospects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('people.0.reply', 'Interested')
            ->where('people.0.campaigns.0.status', 'interested'));
});
