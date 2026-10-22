<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SendMode;
use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Services\CampaignLaunchReadiness;
use App\Domain\Mail\Enums\ConnectionStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function readinessCampaign(User $user, array $overrides = []): Campaign
{
    $campaign = Campaign::factory()->create(array_merge([
        'user_id' => $user->id,
        'status' => CampaignStatus::Draft,
    ], $overrides));

    return $campaign->fresh(['draftVersion.activeSteps', 'mailboxes']);
}

it('reports no blockers for a launchable campaign and exposes readiness on show', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = readinessCampaign($user);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Queued,
    ]);

    $blockers = app(CampaignLaunchReadiness::class)->blockers($campaign->fresh());
    expect($blockers)->toBe([]);

    $this->actingAs($user)
        ->get(route('campaigns.show', $campaign))
        ->assertInertia(fn (Assert $page) => $page
            ->where('readiness.ready', true)
            ->where('readiness.blockers', []));
});

it('names each launch blocker with a stable id and section', function () {
    $user = User::factory()->create();
    $campaign = readinessCampaign($user);
    $campaign->forceFill(['send_mode' => SendMode::Pin])->save();

    $ids = collect(app(CampaignLaunchReadiness::class)->blockers($campaign->fresh()))
        ->pluck('id')
        ->all();

    expect($ids)->toContain('no_connected_mailbox')
        ->and($ids)->toContain('no_recipients')
        ->and($ids)->toContain('pin_needs_one_mailbox');

    $mailbox = Mailbox::factory()->create([
        'user_id' => $user->id,
        'status' => ConnectionStatus::Disconnected,
    ]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);

    expect(collect(app(CampaignLaunchReadiness::class)->blockers($campaign->fresh()))->pluck('id'))
        ->toContain('no_connected_mailbox');

    $connected = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign->mailboxes()->sync([
        $connected->id => ['position' => 1],
        Mailbox::factory()->create(['user_id' => $user->id])->id => ['position' => 2],
    ]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $connected->id,
        'status' => RecipientStatus::Queued,
    ]);

    expect(collect(app(CampaignLaunchReadiness::class)->blockers($campaign->fresh()))->pluck('id')->all())
        ->toBe(['pin_needs_one_mailbox']);
});

it('treats paused recipients as satisfying the audience requirement', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = readinessCampaign($user, [
        'status' => CampaignStatus::Completed,
        'completed_at' => now(),
    ]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Paused,
    ]);

    expect(app(CampaignLaunchReadiness::class)->blockers($campaign->fresh()))->toBe([]);
});

it('reports sequence structure blockers', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = readinessCampaign($user);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Queued,
    ]);

    $step = $campaign->draftVersion->activeSteps->first();
    $step->forceFill([
        'delay_minutes' => 60,
        'send_as' => SequenceSendAs::Reply,
        'subject_template' => '',
        'body_template' => '',
    ])->save();

    $ids = collect(app(CampaignLaunchReadiness::class)->blockers($campaign->fresh(['draftVersion.activeSteps'])))
        ->pluck('id')
        ->all();

    expect($ids)->toContain('first_step_not_immediate')
        ->and($ids)->toContain('step_missing_body')
        ->and($ids)->toContain('reply_without_parent');

    $step->forceFill([
        'delay_minutes' => 0,
        'send_as' => SequenceSendAs::NewThread,
        'subject_template' => 'Hello {{unknown}}',
        'body_template' => 'Hi {{first_name}}',
    ])->save();

    $ids = collect(app(CampaignLaunchReadiness::class)->blockers($campaign->fresh(['draftVersion.activeSteps'])))
        ->pluck('id')
        ->all();

    expect($ids)->toContain('unknown_variable');
});
