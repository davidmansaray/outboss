<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Services\ScheduleDueCampaignSends;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Person;
use App\Models\SendAttempt;
use App\Models\User;

function completableCampaign(): array
{
    FakeMailProvider::reset();
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'status' => CampaignStatus::Active,
        'business_days_only' => false,
        'launched_at' => now()->subHour(),
    ]);
    $campaign->forceFill([
        'published_sequence_version_id' => $campaign->draft_sequence_version_id,
    ])->save();
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'sequence_version_id' => $campaign->draft_sequence_version_id,
        'next_sequence_step_id' => $step->id,
        'status' => RecipientStatus::Active,
        'next_send_at' => now()->subMinute(),
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
        'first_name' => 'Maya',
        'company' => 'Stripe',
    ]);

    return compact('user', 'mailbox', 'campaign', 'recipient', 'step');
}

it('renames a campaign in any status including completed', function () {
    $user = User::factory()->create();

    foreach ([CampaignStatus::Draft, CampaignStatus::Active, CampaignStatus::Paused, CampaignStatus::Completed] as $status) {
        $campaign = Campaign::factory()->create([
            'user_id' => $user->id,
            'status' => $status,
            'name' => 'Original '.$status->value,
            'completed_at' => $status === CampaignStatus::Completed ? now() : null,
        ]);

        $this->actingAs($user)
            ->patch(route('campaigns.update', $campaign), ['name' => 'Renamed '.$status->value])
            ->assertRedirect()
            ->assertSessionHas('success', 'Campaign renamed.');

        expect($campaign->fresh()->name)->toBe('Renamed '.$status->value);
    }
});

it('rejects a rename over 120 characters', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patch(route('campaigns.update', $campaign), ['name' => str_repeat('a', 121)])
        ->assertSessionHasErrors('name');
});

it('updates a draft campaign send window and weekend policy', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'send_window_start' => '09:00',
        'send_window_end' => '17:00',
        'business_days_only' => true,
    ]);

    $this->actingAs($user)
        ->patch(route('campaigns.update', $campaign), [
            'send_window_start' => '08:30',
            'send_window_end' => '18:15',
            'business_days_only' => false,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $campaign->refresh();

    expect(substr((string) $campaign->send_window_start, 0, 5))->toBe('08:30')
        ->and(substr((string) $campaign->send_window_end, 0, 5))->toBe('18:15')
        ->and($campaign->business_days_only)->toBeFalse();
});

it('rejects an invalid or active campaign send window change', function () {
    $user = User::factory()->create();
    $draft = Campaign::factory()->create(['user_id' => $user->id]);
    $active = Campaign::factory()->create([
        'user_id' => $user->id,
        'status' => CampaignStatus::Active,
    ]);

    $this->actingAs($user)
        ->patch(route('campaigns.update', $draft), [
            'send_window_start' => '17:00',
            'send_window_end' => '09:00',
        ])
        ->assertSessionHasErrors('send_window_end');

    $this->actingAs($user)
        ->patch(route('campaigns.update', $active), [
            'send_window_start' => '08:30',
            'send_window_end' => '18:15',
            'business_days_only' => false,
        ])
        ->assertSessionHasErrors([
            'send_window_start',
            'send_window_end',
            'business_days_only',
        ]);

    $active->refresh();

    expect(substr((string) $active->send_window_start, 0, 5))->toBe('09:00')
        ->and(substr((string) $active->send_window_end, 0, 5))->toBe('17:00')
        ->and($active->business_days_only)->toBeTrue();
});

it('completes an active campaign without rewriting recipient statuses', function () {
    ['user' => $user, 'campaign' => $campaign, 'recipient' => $recipient] = completableCampaign();

    $attempt = SendAttempt::factory()->create([
        'user_id' => $user->id,
        'campaign_recipient_id' => $recipient->id,
        'mailbox_id' => $recipient->mailbox_id,
        'workspace_id' => $campaign->getAttribute('workspace_id'),
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Scheduled,
        'idempotency_key' => 'complete-test-'.uniqid(),
    ]);

    $this->actingAs($user)
        ->post(route('campaigns.complete', $campaign))
        ->assertRedirect()
        ->assertSessionHas('success', 'Campaign completed.');

    $campaign->refresh();
    $recipient->refresh();
    $attempt->refresh();

    expect($campaign->status)->toBe(CampaignStatus::Completed)
        ->and($campaign->completed_at)->not->toBeNull()
        ->and($recipient->status)->toBe(RecipientStatus::Active)
        ->and($recipient->remaining_delay_seconds)->not->toBeNull()
        ->and($attempt->status)->toBe(SendAttemptStatus::Deferred)
        ->and($attempt->deferred_reason)->toBe('campaign_completed');

    app(ScheduleDueCampaignSends::class)->handle();
    expect(SendAttempt::query()
        ->where('campaign_recipient_id', $recipient->id)
        ->where('status', SendAttemptStatus::Queued)
        ->count())->toBe(0);
});

it('allows completing a paused campaign and rejects draft or completed', function () {
    $user = User::factory()->create();
    $paused = Campaign::factory()->create([
        'user_id' => $user->id,
        'status' => CampaignStatus::Paused,
        'paused_at' => now(),
    ]);
    $draft = Campaign::factory()->create([
        'user_id' => $user->id,
        'status' => CampaignStatus::Draft,
    ]);
    $completed = Campaign::factory()->create([
        'user_id' => $user->id,
        'status' => CampaignStatus::Completed,
        'completed_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('campaigns.complete', $paused))
        ->assertRedirect();
    expect($paused->fresh()->status)->toBe(CampaignStatus::Completed);

    $this->actingAs($user)
        ->post(route('campaigns.complete', $draft))
        ->assertSessionHasErrors('status');

    $this->actingAs($user)
        ->post(route('campaigns.complete', $completed))
        ->assertSessionHasErrors('status');
});

it('queues enrolments on a completed campaign and resumes them later', function () {
    ['user' => $user, 'campaign' => $campaign, 'recipient' => $recipient, 'mailbox' => $mailbox] = completableCampaign();

    $this->actingAs($user)->post(route('campaigns.complete', $campaign))->assertRedirect();

    $person = Person::factory()->create([
        'workspace_id' => $campaign->getAttribute('workspace_id'),
        'email_address' => 'new@acme.com',
        'email_normalized' => 'new@acme.com',
    ]);

    $this->actingAs($user)
        ->post(route('campaigns.enrollments.store', $campaign), [
            'person_ids' => [$person->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('enrollment.notice');

    $campaign->refresh();
    $queued = $campaign->recipients()->where('email_normalized', 'new@acme.com')->first();

    expect($campaign->status)->toBe(CampaignStatus::Completed)
        ->and($campaign->completed_at)->not->toBeNull()
        ->and($queued->status)->toBe(RecipientStatus::Queued)
        ->and($queued->next_send_at)->toBeNull()
        ->and($recipient->fresh()->status)->toBe(RecipientStatus::Active);

    $this->actingAs($user)
        ->post(route('campaigns.resume', $campaign))
        ->assertRedirect();

    $campaign->refresh();
    expect($campaign->status)->toBe(CampaignStatus::Active)
        ->and($campaign->completed_at)->toBeNull()
        ->and($queued->fresh()->status)->toBe(RecipientStatus::Active)
        ->and($queued->fresh()->next_send_at)->not->toBeNull()
        ->and($recipient->fresh()->remaining_delay_seconds)->toBeNull()
        ->and($recipient->fresh()->next_send_at)->not->toBeNull()
        ->and($mailbox->id)->toBe($queued->mailbox_id);
});

it('refuses sending-setup changes on active and completed campaigns', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $other = Mailbox::factory()->create(['user_id' => $user->id]);
    $active = Campaign::factory()->create([
        'user_id' => $user->id,
        'status' => CampaignStatus::Active,
        'send_mode' => 'rotate',
    ]);
    $active->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $completed = Campaign::factory()->create([
        'user_id' => $user->id,
        'status' => CampaignStatus::Completed,
        'completed_at' => now(),
        'send_mode' => 'rotate',
    ]);
    $completed->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $paused = Campaign::factory()->create([
        'user_id' => $user->id,
        'status' => CampaignStatus::Paused,
        'send_mode' => 'rotate',
    ]);
    $paused->mailboxes()->sync([$mailbox->id => ['position' => 1]]);

    $this->actingAs($user)
        ->patch(route('campaigns.update', $active), [
            'send_mode' => 'pin',
            'mailbox_ids' => [$other->id],
        ])
        ->assertSessionHasErrors(['send_mode', 'mailbox_ids']);
    expect($active->fresh()->send_mode->value)->toBe('rotate')
        ->and($active->mailboxes()->pluck('mailboxes.id')->all())->toBe([$mailbox->id]);

    $this->actingAs($user)
        ->patch(route('campaigns.update', $completed), [
            'send_mode' => 'pin',
            'mailbox_ids' => [$other->id],
        ])
        ->assertSessionHasErrors(['send_mode', 'mailbox_ids']);

    $this->actingAs($user)
        ->patch(route('campaigns.update', $paused), [
            'send_mode' => 'pin',
            'mailbox_ids' => [$other->id],
        ])
        ->assertRedirect();
    expect($paused->fresh()->send_mode->value)->toBe('pin')
        ->and($paused->mailboxes()->pluck('mailboxes.id')->all())->toBe([$other->id]);

    $this->actingAs($user)
        ->patch(route('campaigns.update', $active), ['name' => 'Still live'])
        ->assertRedirect();
    expect($active->fresh()->name)->toBe('Still live');
});

it('pauses and removes a recipient while the campaign is active', function () {
    ['user' => $user, 'campaign' => $campaign, 'recipient' => $recipient, 'mailbox' => $mailbox] = completableCampaign();
    $other = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'sequence_version_id' => $campaign->draft_sequence_version_id,
        'next_sequence_step_id' => $recipient->next_sequence_step_id,
        'status' => RecipientStatus::Active,
        'next_send_at' => now()->subMinute(),
        'email_address' => 'other@acme.com',
        'email_normalized' => 'other@acme.com',
    ]);

    $this->actingAs($user)
        ->patch(route('campaign-recipients.update', $recipient), ['action' => 'pause'])
        ->assertRedirect();
    expect($recipient->fresh()->status)->toBe(RecipientStatus::Paused);

    app(ScheduleDueCampaignSends::class)->handle();
    expect(SendAttempt::query()->where('campaign_recipient_id', $recipient->id)->count())->toBe(0);

    $this->actingAs($user)
        ->patch(route('campaign-recipients.update', $recipient), ['action' => 'resume'])
        ->assertRedirect();
    expect($recipient->fresh()->status)->toBe(RecipientStatus::Active);

    $this->actingAs($user)
        ->delete(route('campaign-recipients.destroy', $other))
        ->assertRedirect();
    expect($other->fresh()->status)->toBe(RecipientStatus::Removed);

    app(ScheduleDueCampaignSends::class)->handle();
    expect(SendAttempt::query()->where('campaign_recipient_id', $other->id)->count())->toBe(0);
});
