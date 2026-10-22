<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Mail\Enums\MessageDirection;
use App\Models\Message;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

it('sends campaign mail without a tracking pixel by default', function () {
    $this->travelTo(now()->setTime(10, 0));
    [, , , $recipient] = launchedCampaign();

    expect($recipient->campaign->fresh()->open_tracking_enabled)->toBeFalse();

    sendDueCampaigns();

    $html = Message::query()
        ->where('campaign_recipient_id', $recipient->id)
        ->where('direction', MessageDirection::Outbound)
        ->value('body_html_sanitized');

    expect($html)->not->toContain('/t/');
});

it('adds a tracking pixel when open tracking is enabled before launch', function () {
    $this->travelTo(now()->setTime(10, 0));
    [$user, , $campaign, $recipient] = launchedCampaign();
    $campaign->forceFill(['status' => CampaignStatus::Paused])->save();

    $this->actingAs($user)
        ->patch(route('campaigns.update', $campaign), [
            'open_tracking_enabled' => true,
        ])
        ->assertRedirect();

    $campaign->forceFill(['status' => CampaignStatus::Active])->save();
    Config::set('outboss.campaign_sending_enabled', true);
    sendDueCampaigns();

    $message = Message::query()
        ->where('campaign_recipient_id', $recipient->id)
        ->where('direction', MessageDirection::Outbound)
        ->firstOrFail();

    expect($message->tracking_token)->not->toBeNull()
        ->and($message->body_html_sanitized)->toContain('/t/'.$message->tracking_token.'.gif');
});

it('refuses to toggle open tracking on an active campaign', function () {
    [$user, , $campaign] = launchedCampaign();

    $this->actingAs($user)
        ->patch(route('campaigns.update', $campaign), [
            'open_tracking_enabled' => true,
        ])
        ->assertSessionHasErrors('open_tracking_enabled');

    expect($campaign->fresh()->open_tracking_enabled)->toBeFalse();
});

it('still records opens for previously issued tokens', function () {
    $this->travelTo(now()->setTime(10, 0));
    [, , , $recipient] = launchedCampaign();
    $token = (string) Str::uuid();
    Message::factory()->outbound()->create([
        'mailbox_id' => $recipient->mailbox_id,
        'campaign_recipient_id' => $recipient->id,
        'tracking_token' => $token,
    ]);

    $this->get('/t/'.$token.'.gif')->assertOk();
});
