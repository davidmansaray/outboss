<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\SequenceStep;
use App\Models\User;

function draftSteps(Campaign $campaign)
{
    return $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps;
}

it('numbers new follow-ups when the default title is already in use', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    foreach (range(1, 3) as $attempt) {
        $this->actingAs($user)
            ->post(route('campaigns.steps.store', $campaign), ['title' => 'Follow-up'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    expect(draftSteps($campaign)->pluck('title')->all())->toBe([
        'Intro',
        'Follow-up',
        'Follow-up 2',
        'Follow-up 3',
    ]);
});

it('continues after the highest existing follow-up number', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $versionId = draftSteps($campaign)->first()->sequence_version_id;

    SequenceStep::factory()->create([
        'sequence_version_id' => $versionId,
        'position' => 2,
        'title' => 'Follow-up',
    ]);
    SequenceStep::factory()->create([
        'sequence_version_id' => $versionId,
        'position' => 3,
        'title' => 'Follow-up 4',
    ]);

    $this->actingAs($user)
        ->post(route('campaigns.steps.store', $campaign), ['title' => 'Follow-up'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(draftSteps($campaign)->last()->title)->toBe('Follow-up 5');
});

it('keeps numbered duplicate titles within the editable length limit', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $title = str_repeat('A', 80);

    foreach (range(1, 3) as $attempt) {
        $this->actingAs($user)
            ->post(route('campaigns.steps.store', $campaign), ['title' => $title])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    $titles = draftSteps($campaign)->pluck('title')->slice(1)->values();

    expect($titles->all())->toBe([
        $title,
        str_repeat('A', 78).' 2',
        str_repeat('A', 78).' 3',
    ])->and($titles->every(fn (string $candidate): bool => mb_strlen($candidate) <= 80))->toBeTrue();
});

it('reorders a draft campaign sequence and leaves soft-deleted steps out of the active list', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $intro = draftSteps($campaign)->first();
    $followUp = SequenceStep::factory()->create([
        'sequence_version_id' => $intro->sequence_version_id,
        'position' => 2,
        'title' => 'Follow-up',
        'delay_minutes' => 4320,
        'send_as' => SequenceSendAs::Reply,
        'body_template' => 'Bumping this once.',
    ]);
    $removed = SequenceStep::factory()->create([
        'sequence_version_id' => $intro->sequence_version_id,
        'position' => 3,
        'title' => 'Removed',
        'deleted_at' => now(),
    ]);

    $this->actingAs($user)
        ->put(route('campaigns.steps.reorder', $campaign), [
            'step_ids' => [$followUp->id, $intro->id],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $active = draftSteps($campaign);
    expect($active->pluck('id')->all())->toBe([$followUp->id, $intro->id])
        ->and($active->pluck('position')->all())->toBe([1, 2])
        ->and($removed->fresh()->position)->toBe(3)
        ->and($removed->fresh()->deleted_at)->not->toBeNull();
});

it('clones a published sequence before reordering a paused campaign', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id, 'business_days_only' => false]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $intro = draftSteps($campaign)->first();
    $intro->forceFill(['subject_spintax_enabled' => true])->save();
    $followUp = SequenceStep::factory()->create([
        'sequence_version_id' => $intro->sequence_version_id,
        'position' => 2,
        'title' => 'Follow-up',
        'delay_minutes' => 0,
        'send_as' => SequenceSendAs::Reply,
        'body_template' => 'Bumping this once.',
    ]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'sequence_version_id' => $intro->sequence_version_id,
        'next_sequence_step_id' => $intro->id,
        'status' => RecipientStatus::Queued,
        'first_sent_at' => now(),
    ]);
    app(LaunchCampaignService::class)->handle($campaign->fresh(), $user);
    app(LaunchCampaignService::class)->pause($campaign->fresh());
    $publishedOrder = $campaign->fresh('publishedVersion.activeSteps')->publishedVersion->activeSteps->pluck('id')->all();

    $this->actingAs($user)
        ->put(route('campaigns.steps.reorder', $campaign), [
            'step_ids' => [$followUp->id, $intro->id],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $campaign->refresh();
    expect($campaign->versions()->count())->toBe(2)
        ->and($campaign->draft_sequence_version_id)->not->toBe($campaign->published_sequence_version_id)
        ->and($campaign->publishedVersion->activeSteps->pluck('id')->all())->toBe($publishedOrder)
        ->and($recipient->fresh()->sequence_version_id)->toBe($campaign->published_sequence_version_id)
        ->and($campaign->draftVersion->activeSteps->pluck('title')->all())->toBe(['Follow-up', 'Intro'])
        ->and($campaign->draftVersion->activeSteps->firstWhere('title', 'Intro')->subject_spintax_enabled)->toBeTrue();
});

it('refuses to reorder an active campaign', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id, 'business_days_only' => false]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $intro = draftSteps($campaign)->first();
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Queued,
    ]);
    app(LaunchCampaignService::class)->handle($campaign->fresh(), $user);

    $this->actingAs($user)
        ->from(route('campaigns.show', $campaign))
        ->put(route('campaigns.steps.reorder', $campaign), [
            'step_ids' => [$intro->id],
        ])
        ->assertRedirect(route('campaigns.show', $campaign))
        ->assertSessionHasErrors([
            'sequence' => 'Pause the campaign before editing the sequence.',
        ]);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Active)
        ->and(draftSteps($campaign)->first()->id)->toBe($intro->id);
});

it('rejects a stale or foreign reorder payload', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $intro = draftSteps($campaign)->first();
    $followUp = SequenceStep::factory()->create([
        'sequence_version_id' => $intro->sequence_version_id,
        'position' => 2,
        'title' => 'Follow-up',
        'send_as' => SequenceSendAs::Reply,
        'body_template' => 'Bumping this once.',
    ]);
    $deleted = SequenceStep::factory()->create([
        'sequence_version_id' => $intro->sequence_version_id,
        'position' => 3,
        'deleted_at' => now(),
    ]);
    $foreign = Campaign::factory()->create()->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();

    foreach ([
        [$followUp->id],
        [$intro->id, $followUp->id, $foreign->id],
        [$intro->id, $followUp->id, $deleted->id],
    ] as $payload) {
        $this->actingAs($user)
            ->from(route('campaigns.show', $campaign))
            ->put(route('campaigns.steps.reorder', $campaign), [
                'step_ids' => $payload,
            ])
            ->assertRedirect(route('campaigns.show', $campaign))
            ->assertSessionHasErrors([
                'sequence' => 'The sequence changed. Reload and try again.',
            ]);
    }

    expect(draftSteps($campaign)->pluck('id')->all())->toBe([$intro->id, $followUp->id]);
});

it('points queued recipients at the new first step after reorder and launch', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id, 'business_days_only' => false]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $intro = draftSteps($campaign)->first();
    $alternate = SequenceStep::factory()->create([
        'sequence_version_id' => $intro->sequence_version_id,
        'position' => 2,
        'title' => 'Alternate intro',
        'delay_minutes' => 0,
        'send_as' => SequenceSendAs::NewThread,
        'subject_template' => 'Another start',
        'body_template' => 'Hello {{first_name}}',
    ]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'sequence_version_id' => $intro->sequence_version_id,
        'next_sequence_step_id' => $intro->id,
        'status' => RecipientStatus::Queued,
    ]);

    $this->actingAs($user)
        ->put(route('campaigns.steps.reorder', $campaign), [
            'step_ids' => [$alternate->id, $intro->id],
        ])
        ->assertRedirect();

    app(LaunchCampaignService::class)->handle($campaign->fresh(), $user);

    expect($recipient->fresh()->next_sequence_step_id)->toBe($alternate->id);
});

it('refuses a stale published step after the draft has been reordered', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id, 'business_days_only' => false]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $intro = draftSteps($campaign)->first();
    SequenceStep::factory()->create([
        'sequence_version_id' => $intro->sequence_version_id,
        'position' => 2,
        'title' => 'Follow-up',
        'delay_minutes' => 0,
        'send_as' => SequenceSendAs::Reply,
        'body_template' => 'Bumping this once.',
    ]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Queued,
        'first_sent_at' => now(),
    ]);
    app(LaunchCampaignService::class)->handle($campaign->fresh(), $user);
    app(LaunchCampaignService::class)->pause($campaign->fresh());

    $this->actingAs($user)
        ->post(route('campaigns.steps.store', $campaign), ['title' => 'Later'])
        ->assertRedirect();

    $campaign->refresh();
    $draftIds = $campaign->draftVersion->activeSteps->pluck('id')->reverse()->values()->all();

    $this->actingAs($user)
        ->put(route('campaigns.steps.reorder', $campaign), [
            'step_ids' => $draftIds,
        ])
        ->assertRedirect();

    $draftTitles = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->pluck('title', 'id');

    $this->actingAs($user)
        ->from(route('campaigns.show', $campaign))
        ->patch(route('sequence-steps.update', $intro), ['title' => 'Hacked'])
        ->assertRedirect(route('campaigns.show', $campaign))
        ->assertSessionHasErrors([
            'sequence' => 'Published steps cannot be edited.',
        ]);

    expect($campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->pluck('title', 'id')->all())
        ->toBe($draftTitles->all())
        ->and($intro->fresh()->title)->toBe('Intro');
});
