<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Services\MailboxSendBudget;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\SendAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

function budgetMailbox(): array
{
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'daily_send_cap' => 2,
    ]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
        'email_domain' => 'stripe.com',
        'status' => RecipientStatus::Active,
    ]);

    return [$mailbox, $recipient];
}

function consumeBudget(Mailbox $mailbox, CampaignRecipient $recipient, SendAttemptStatus $status): void
{
    SendAttempt::factory()->create([
        'user_id' => $mailbox->user_id,
        'workspace_id' => $mailbox->workspace_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $recipient->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => $status,
        'lease_acquired_at' => now(),
    ]);
}

it('counts provider-reaching attempts against the daily cap', function () {
    [$mailbox, $recipient] = budgetMailbox();
    consumeBudget($mailbox, $recipient, SendAttemptStatus::Sent);
    consumeBudget($mailbox, $recipient, SendAttemptStatus::Unknown);

    $budget = app(MailboxSendBudget::class);

    expect($budget->remainingDailyBudget($mailbox))->toBe(0)
        ->and($budget->gate($mailbox, $recipient)->deferredReason)->toBe(MailboxSendBudget::REASON_DAILY_CAP);
});

it('does not count deferred attempts toward the cap', function () {
    [$mailbox, $recipient] = budgetMailbox();
    consumeBudget($mailbox, $recipient, SendAttemptStatus::Deferred);

    expect(app(MailboxSendBudget::class)->remainingDailyBudget($mailbox))->toBe(2);
});

it('throttles a recipient domain after the hourly limit', function () {
    Config::set('outboss.sending.domain_hourly_limit', 1);
    [$mailbox, $recipient] = budgetMailbox();
    consumeBudget($mailbox, $recipient, SendAttemptStatus::Sent);

    $decision = app(MailboxSendBudget::class)->gate($mailbox, $recipient);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->deferredReason)->toBe(MailboxSendBudget::REASON_DOMAIN);
});

it('reserves a campaign target across inboxes without counting the current lease candidate', function () {
    [$mailbox, $firstRecipient] = budgetMailbox();
    $campaign = $firstRecipient->campaign()->firstOrFail();
    $campaign->forceFill(['daily_send_limit' => 1])->save();
    $secondMailbox = Mailbox::factory()->create([
        'user_id' => $campaign->user_id,
        'workspace_id' => $campaign->workspace_id,
        'daily_send_cap' => 50,
    ]);
    $secondRecipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $campaign->workspace_id,
        'mailbox_id' => $secondMailbox->id,
        'email_address' => 'other@stripe.com',
        'email_normalized' => 'other@stripe.com',
        'email_domain' => 'stripe.com',
        'status' => RecipientStatus::Active,
    ]);
    $firstAttempt = SendAttempt::factory()->create([
        'workspace_id' => $campaign->workspace_id,
        'mailbox_id' => $mailbox->id,
        'campaign_recipient_id' => $firstRecipient->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Queued,
    ]);
    $budget = app(MailboxSendBudget::class);

    expect($budget->gate(
        $mailbox,
        $firstRecipient,
        $campaign,
        includeReservedCampaign: true,
        excludeCampaignAttemptId: (int) $firstAttempt->id,
    )->allowed)->toBeTrue();

    $firstAttempt->forceFill(['status' => SendAttemptStatus::LeaseAcquired])->save();
    $secondAttempt = SendAttempt::factory()->create([
        'workspace_id' => $campaign->workspace_id,
        'mailbox_id' => $secondMailbox->id,
        'campaign_recipient_id' => $secondRecipient->id,
        'kind' => SendAttemptKind::Campaign,
        'status' => SendAttemptStatus::Queued,
    ]);
    $decision = $budget->gate(
        $secondMailbox,
        $secondRecipient,
        $campaign,
        includeReservedCampaign: true,
        excludeCampaignAttemptId: (int) $secondAttempt->id,
    );

    expect($decision->allowed)->toBeFalse()
        ->and($decision->deferredReason)->toBe(MailboxSendBudget::REASON_CAMPAIGN_DAILY_LIMIT);
});
