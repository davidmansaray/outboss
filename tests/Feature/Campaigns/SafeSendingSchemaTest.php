<?php

use App\Domain\Campaigns\DTOs\EnrollmentCandidate;
use App\Domain\Campaigns\Services\EnrollPeopleInCampaign;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('adds the safe-sending columns and backfills enrolment fields', function () {
    expect(Schema::hasColumns('mailboxes', [
        'daily_send_cap',
        'next_send_slot_at',
        'sending_paused_at',
        'sending_paused_reason',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('campaigns', 'open_tracking_enabled'))->toBeTrue()
        ->and(Schema::hasColumns('campaign_recipients', [
            'email_domain',
            'unsubscribe_token',
        ]))->toBeTrue();

    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign->mailboxes()->attach($mailbox->id, ['position' => 1]);

    DB::table('campaign_recipients')->insert([
        'campaign_id' => $campaign->id,
        'workspace_id' => $campaign->workspace_id,
        'mailbox_id' => $mailbox->id,
        'sequence_version_id' => $campaign->draft_sequence_version_id,
        'email_address' => 'legacy@acme.test',
        'email_normalized' => 'legacy@acme.test',
        'status' => 'queued',
        'mailbox_assignment' => 'rotation',
        'lock_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $legacy = CampaignRecipient::query()->where('email_normalized', 'legacy@acme.test')->firstOrFail();

    expect($legacy->email_domain)->toBeNull()
        ->and($legacy->unsubscribe_token)->toBeNull();

    $legacy->forceFill([
        'email_domain' => EmailNormalizer::domain($legacy->email_normalized),
        'unsubscribe_token' => (string) Str::uuid(),
    ])->save();

    expect($legacy->fresh()->email_domain)->toBe('acme.test')
        ->and($legacy->fresh()->unsubscribe_token)->not->toBeNull();

    expect($campaign->fresh()->open_tracking_enabled)->toBeFalse();
});

it('writes email domain and unsubscribe token when enrolling a person', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign->mailboxes()->attach($mailbox->id, ['position' => 1]);

    $result = app(EnrollPeopleInCampaign::class)->handle($campaign, [
        new EnrollmentCandidate(
            personId: null,
            accountId: null,
            emailAddress: 'Maya@Stripe.com',
            emailNormalized: 'maya@stripe.com',
            fullName: 'Maya Chen',
            firstName: 'Maya',
            company: 'Stripe',
        ),
    ]);

    $recipient = CampaignRecipient::query()->where('campaign_id', $campaign->id)->firstOrFail();

    expect($result->enrolled)->toBe(1)
        ->and($recipient->email_domain)->toBe('stripe.com')
        ->and($recipient->unsubscribe_token)->not->toBeNull();
});
