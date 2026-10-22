<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Services\AcquireRecipientSendLease;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Domain\Campaigns\Services\ScheduleDueCampaignSends;
use App\Domain\Mail\Enums\MessageStatus;
use App\Domain\Mail\Enums\SendAttemptKind;
use App\Domain\Mail\Enums\SendAttemptStatus;
use App\Domain\Mail\Enums\SendResolution;
use App\Domain\Mail\Providers\FakeMailProvider;
use App\Domain\Mail\Services\ReconcileUnknownSend;
use App\Domain\Mail\Services\ResolveManualReviewSend;
use App\Domain\Mail\Services\SweepStalledAttempts;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\SendAttempt;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Support\Facades\Config;

/**
 * @return array{0: User, 1: Mailbox, 2: Campaign, 3: CampaignRecipient, 4: SequenceStep}
 */
function recoveryCampaignFixture(): array
{
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'business_days_only' => false,
    ]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->firstOrFail();
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'sequence_version_id' => $campaign->draft_sequence_version_id,
        'next_sequence_step_id' => $step->id,
        'status' => RecipientStatus::Queued,
        'email_address' => 'recovery@example.test',
        'email_normalized' => 'recovery@example.test',
        'first_name' => 'Recovery',
        'company' => 'Example',
    ]);

    app(LaunchCampaignService::class)->handle($campaign->fresh(), $user);

    return [$user, $mailbox, $campaign->fresh(), $recipient->fresh(), $step];
}

it('moves a stale sending worker attempt into unknown reconciliation without releasing its lease', function () {
    $this->travelTo(now()->setTime(10, 0));
    [, , , $recipient] = recoveryCampaignFixture();
    Config::set('outboss.campaign_sending_enabled', true);

    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);

    $attempt = SendAttempt::query()->where('kind', SendAttemptKind::Campaign)->firstOrFail();
    $leased = app(AcquireRecipientSendLease::class)->handle($attempt);
    expect($leased)->not->toBeNull();

    $leaseToken = $recipient->fresh()->send_lease_token;
    $attemptedAt = now()->subMinutes(7);
    $attempt->forceFill([
        'status' => SendAttemptStatus::Sending,
        'provider_phase' => 'send_submitted',
        'provider_marker' => 'marker-stale-worker',
        'provider_request_id' => 'request-stale-worker',
        'provider_attempted_at' => $attemptedAt,
        'provider_unknown_count' => 0,
    ])->save();

    expect(app(SweepStalledAttempts::class)->handle())->toBe(1);

    $recovered = $attempt->fresh();
    expect($recovered->status)->toBe(SendAttemptStatus::Unknown)
        ->and($recovered->due_at)->not->toBeNull()
        ->and($recovered->provider_phase)->toBe('send_submitted')
        ->and($recovered->provider_marker)->toBe('marker-stale-worker')
        ->and($recovered->provider_request_id)->toBe('request-stale-worker')
        ->and($recovered->provider_attempted_at)->toEqual($attemptedAt)
        ->and($recovered->provider_unknown_count)->toBe(1)
        ->and($recipient->fresh()->send_lease_token)->toBe($leaseToken)
        ->and(app(SweepStalledAttempts::class)->handle())->toBe(0);

    $providerSendCount = count(FakeMailProvider::$store['sends'] ?? []);
    expect(app(ReconcileUnknownSend::class)->handle($attempt->id))->toBe(1)
        ->and($attempt->fresh()->status)->toBe(SendAttemptStatus::Deferred)
        ->and($attempt->fresh()->deferred_reason)->toBe('reconciled_not_sent')
        ->and($recipient->fresh()->send_lease_token)->toBeNull()
        ->and(FakeMailProvider::$store['sends'] ?? [])->toHaveCount($providerSendCount);
});

it('uses the shared finalizer for manual sent confirmation and completes every sequence projection', function () {
    $this->travelTo(now()->setTime(10, 0));
    [, , $campaign, $recipient, $firstStep] = recoveryCampaignFixture();
    $secondStep = SequenceStep::factory()->create([
        'sequence_version_id' => $firstStep->sequence_version_id,
        'position' => 2,
        'title' => 'Follow up',
        'delay_minutes' => 0,
        'send_as' => SequenceSendAs::NewThread,
        'subject_template' => 'One more question about {{company}}',
        'body_template' => 'Following up with {{first_name}}.',
    ]);

    // The fixture was launched before the second draft step was added. Put it
    // on the published version for this progression-only test.
    $secondStep->forceFill(['sequence_version_id' => $campaign->published_sequence_version_id])->save();

    Config::set('outboss.campaign_sending_enabled', true);
    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);
    $firstAttempt = SendAttempt::query()->where('sequence_step_id', $firstStep->id)->firstOrFail();
    expect(app(AcquireRecipientSendLease::class)->handle($firstAttempt))->not->toBeNull();
    $firstAttempt->forceFill([
        'status' => SendAttemptStatus::ManualReview,
        'manual_review_cycle' => 1,
        'provider_phase' => 'send_submitted',
    ])->save();
    $providerSendCount = count(FakeMailProvider::$store['sends'] ?? []);

    $resolution = app(ResolveManualReviewSend::class)->handle(
        $firstAttempt->fresh(),
        SendResolution::ConfirmedSent,
        'operator@example.test',
        'confirmed in provider Sent folder',
    );

    $resolvedRecipient = $recipient->fresh();
    expect($resolution->review_cycle)->toBe(1)
        ->and($firstAttempt->fresh()->status)->toBe(SendAttemptStatus::Sent)
        ->and($firstAttempt->message->fresh()->status)->toBe(MessageStatus::Sent)
        ->and($resolvedRecipient->status)->toBe(RecipientStatus::Active)
        ->and($resolvedRecipient->next_sequence_step_id)->toBe($secondStep->id)
        ->and($resolvedRecipient->first_sent_at)->not->toBeNull()
        ->and($resolvedRecipient->last_sent_at)->not->toBeNull()
        ->and($resolvedRecipient->send_lease_token)->toBeNull()
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Active)
        ->and($firstAttempt->resolutions()->count())->toBe(1)
        ->and(FakeMailProvider::$store['sends'] ?? [])->toHaveCount($providerSendCount);

    // A retried operator command is an idempotent read of the same review
    // cycle; it must not re-run or duplicate the finalization row.
    expect(app(ResolveManualReviewSend::class)->handle(
        $firstAttempt->fresh(),
        SendResolution::ConfirmedSent,
        'different-operator@example.test',
        'same provider evidence',
    )->getKey())->toBe($resolution->getKey())
        ->and($firstAttempt->resolutions()->count())->toBe(1);

    expect(app(ScheduleDueCampaignSends::class)->handle())->toBe(1);
    $secondAttempt = SendAttempt::query()->where('sequence_step_id', $secondStep->id)->firstOrFail();
    expect(app(AcquireRecipientSendLease::class)->handle($secondAttempt))->not->toBeNull();
    $secondAttempt->forceFill([
        'status' => SendAttemptStatus::ManualReview,
        'manual_review_cycle' => 1,
        'provider_phase' => 'send_submitted',
    ])->save();
    $providerSendCount = count(FakeMailProvider::$store['sends'] ?? []);

    app(ResolveManualReviewSend::class)->handle(
        $secondAttempt->fresh(),
        SendResolution::ConfirmedSent,
        'operator@example.test',
        'confirmed in provider Sent folder',
    );

    expect($recipient->fresh()->status)->toBe(RecipientStatus::Completed)
        ->and($recipient->fresh()->send_lease_token)->toBeNull()
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Completed)
        ->and($campaign->fresh()->completed_at)->not->toBeNull()
        ->and(FakeMailProvider::$store['sends'] ?? [])->toHaveCount($providerSendCount);
});
