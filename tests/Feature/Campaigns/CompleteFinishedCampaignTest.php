<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Services\CompleteFinishedCampaign;
use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Campaigns\Services\PauseResumeRecipient;
use App\Domain\Campaigns\Services\StopCampaignRecipient;
use App\Models\CampaignRecipient;
use App\Models\SendAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('completes only when the final recipient stops and preserves completion on retry', function () {
    $campaign = app(CreateCampaignService::class)->handle(User::factory()->create(), 'Stop completion');
    $campaign->forceFill(['status' => 'active', 'launched_at' => now()])->save();
    [$first, $last] = CampaignRecipient::factory()->count(2)->create(['campaign_id' => $campaign->id])->all();
    DB::transaction(fn () => app(StopCampaignRecipient::class)->handle($first, RecipientStatus::Unsubscribed, 'unsubscribe'));
    expect($campaign->fresh()->status->value)->toBe('active');
    DB::transaction(fn () => app(PauseResumeRecipient::class)->remove($last));
    expect($campaign->fresh()->status->value)->toBe('completed');
    $finished = $campaign->fresh()->completed_at->toAtomString();
    app(CompleteFinishedCampaign::class)->handle($campaign->workspace_id, $campaign->id);
    expect($campaign->fresh()->completed_at->toAtomString())->toBe($finished);
});

it('does not turn an unresolved send into a completed campaign', function (string $attemptState) {
    $campaign = app(CreateCampaignService::class)->handle(User::factory()->create(), 'Unknown outcome');
    $campaign->forceFill(['status' => 'active', 'launched_at' => now()])->save();
    $recipient = CampaignRecipient::factory()->create(['campaign_id' => $campaign->id]);
    $attempt = SendAttempt::factory()->create(['campaign_recipient_id' => $recipient->id,
        'mailbox_id' => $recipient->mailbox_id, 'kind' => 'campaign', 'status' => $attemptState]);
    DB::transaction(fn () => app(StopCampaignRecipient::class)->handle($recipient, RecipientStatus::Unsubscribed, 'unsubscribe'));
    expect($campaign->fresh()->status->value)->toBe('active')
        ->and($attempt->fresh()->status->value)->toBe($attemptState);
})->with(['unknown', 'manual_review', 'sending', 'lease_acquired', 'provider_accepted']);

it('does not complete a draft or another workspace campaign', function () {
    $campaign = app(CreateCampaignService::class)->handle(User::factory()->create(), 'Still draft');
    $recipient = CampaignRecipient::factory()->create(['campaign_id' => $campaign->id]);
    DB::transaction(fn () => app(StopCampaignRecipient::class)->handle($recipient, RecipientStatus::Removed, 'removed'));
    expect($campaign->fresh()->status->value)->toBe('draft');
    $campaign->forceFill(['status' => 'active'])->save();
    app(CompleteFinishedCampaign::class)->handle(User::factory()->create()->currentWorkspace()->id, $campaign->id);
    expect($campaign->fresh()->status->value)->toBe('active');
});

it('retries completion when the recipient stop was already committed', function (string $state) {
    $campaign = app(CreateCampaignService::class)->handle(User::factory()->create(), 'Retry completion');
    $campaign->forceFill(['status' => 'active', 'launched_at' => now()])->save();
    $recipient = CampaignRecipient::factory()->create(['campaign_id' => $campaign->id, 'status' => $state]);
    $changed = DB::transaction(fn () => app(StopCampaignRecipient::class)->handle($recipient, RecipientStatus::Unsubscribed, 'retry'));
    expect($changed)->toBeFalse()->and($campaign->fresh()->status->value)->toBe('completed')
        ->and($recipient->fresh()->status->value)->toBe($state);
})->with(['removed', 'unsubscribed']);
