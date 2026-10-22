<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Mail\Enums\SuppressionReason;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\SuppressionEntry;
use App\Models\User;

it('pauses, resumes, pins, and logically removes a recipient', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $other = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Active,
        'next_send_at' => now()->addHours(2),
    ]);

    $this->actingAs($user)
        ->patch(route('campaign-recipients.update', $recipient), ['action' => 'pause'])
        ->assertRedirect();
    expect($recipient->fresh()->status)->toBe(RecipientStatus::Paused)
        ->and($recipient->fresh()->remaining_delay_seconds)->toBeGreaterThan(0);

    $this->actingAs($user)
        ->patch(route('campaign-recipients.update', $recipient), ['action' => 'resume'])
        ->assertRedirect();
    expect($recipient->fresh()->status)->toBe(RecipientStatus::Active);

    $this->actingAs($user)
        ->patch(route('campaign-recipients.update', $recipient), ['mailbox_id' => $other->id])
        ->assertRedirect();
    expect($recipient->fresh()->mailbox_id)->toBe($other->id);

    $recipient->forceFill(['first_sent_at' => now()])->save();
    $this->actingAs($user)
        ->patch(route('campaign-recipients.update', $recipient), ['mailbox_id' => $mailbox->id])
        ->assertSessionHasErrors();

    $this->actingAs($user)
        ->delete(route('campaign-recipients.destroy', $recipient))
        ->assertRedirect();
    expect($recipient->fresh()->status)->toBe(RecipientStatus::Removed);
});

it('lets a user delete their own suppression and re-import that address', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);

    $entry = SuppressionEntry::factory()->create([
        'user_id' => $user->id,
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
        'reason' => SuppressionReason::Unsubscribe,
    ]);
    SuppressionEntry::factory()->create([
        'user_id' => $other->id,
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
    ]);

    $this->actingAs($user)->get(route('suppressions.index'))->assertOk();
    $this->actingAs($user)->delete(route('suppressions.destroy', $entry))->assertRedirect();
    $this->actingAs($user)->delete(route('suppressions.destroy', SuppressionEntry::query()->where('user_id', $other->id)->first()))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('campaigns.imports.preview', $campaign), [
            'source' => 'paste',
            'rows' => [['email' => 'maya@stripe.com', 'name' => 'Maya', 'company' => 'Stripe']],
            'mapping' => ['email' => 'email', 'name' => 'name', 'company' => 'company'],
        ])
        ->assertRedirect();

    $import = $campaign->fresh()->imports()->first();
    expect($import->ready)->toBe(1)->and($import->suppressed)->toBe(0);
});

it('keeps a completed campaign completed when a ready import commits', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'status' => CampaignStatus::Completed,
        'completed_at' => now(),
        'published_sequence_version_id' => null,
    ]);
    $campaign->forceFill(['published_sequence_version_id' => $campaign->draft_sequence_version_id])->save();
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);

    $this->actingAs($user)
        ->post(route('campaigns.imports.preview', $campaign), [
            'source' => 'paste',
            'rows' => [['email' => 'new@acme.com', 'name' => 'New', 'company' => 'Acme']],
            'mapping' => ['email' => 'email', 'name' => 'name', 'company' => 'company'],
        ])
        ->assertRedirect();

    $import = $campaign->fresh()->imports()->first();
    $this->actingAs($user)
        ->post(route('campaigns.imports.commit', [$campaign, $import]), [
            'expected_mapping_version' => $import->mapping_version,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'People added. Campaign is completed — resume it to start sending to newly added people.');

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Completed)
        ->and($campaign->fresh()->completed_at)->not->toBeNull()
        ->and($campaign->recipients()->where('email_normalized', 'new@acme.com')->first()->status)
        ->toBe(RecipientStatus::Queued)
        ->and($campaign->recipients()->where('email_normalized', 'new@acme.com')->first()->next_send_at)
        ->toBeNull();
});
