<?php

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SequenceVersionStatus;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Domain\Campaigns\Services\PublishSequenceVersion;
use App\Domain\Campaigns\Services\SequenceStepEditor;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function versionedCampaign(): array
{
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id, 'business_days_only' => false]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();

    return [$user, $mailbox, $campaign, $step];
}

it('retires the previous published version when a new draft is launched', function () {
    [$user, $mailbox, $campaign] = versionedCampaign();
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Queued,
        'first_sent_at' => now(),
    ]);

    $launch = app(LaunchCampaignService::class);
    $launch->handle($campaign->fresh(), $user);
    $firstPublished = $campaign->fresh('publishedVersion')->publishedVersion;
    $publishedAt = $firstPublished->published_at;
    expect($firstPublished->status)->toBe(SequenceVersionStatus::Published)
        ->and($firstPublished->version)->toBe(1);

    $launch->pause($campaign->fresh());
    app(SequenceStepEditor::class)->add($campaign->fresh(), 'Bump');
    $launch->resume($campaign->fresh(), $user);

    $campaign->refresh();
    $firstPublished->refresh();
    $second = $campaign->publishedVersion;

    expect($campaign->status)->toBe(CampaignStatus::Active)
        ->and($firstPublished->status)->toBe(SequenceVersionStatus::Retired)
        ->and($firstPublished->published_at?->equalTo($publishedAt))->toBeTrue()
        ->and($second->status)->toBe(SequenceVersionStatus::Published)
        ->and($second->version)->toBe(2);

    $launch->pause($campaign->fresh());
    app(SequenceStepEditor::class)->add($campaign->fresh(), 'Later');
    $launch->resume($campaign->fresh(), $user);

    expect($second->fresh()->status)->toBe(SequenceVersionStatus::Retired)
        ->and($campaign->fresh('publishedVersion')->publishedVersion->version)->toBe(3);
});

it('keeps retired steps immutable', function () {
    [$user, $mailbox, $campaign] = versionedCampaign();
    $intro = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Queued,
        'first_sent_at' => now(),
    ]);

    $launch = app(LaunchCampaignService::class);
    $launch->handle($campaign->fresh(), $user);
    $launch->pause($campaign->fresh());
    app(SequenceStepEditor::class)->add($campaign->fresh(), 'Bump');
    $launch->resume($campaign->fresh(), $user);

    $this->actingAs($user)
        ->from(route('campaigns.show', $campaign))
        ->patch(route('sequence-steps.update', $intro), ['title' => 'Hacked'])
        ->assertRedirect(route('campaigns.show', $campaign))
        ->assertSessionHasErrors([
            'sequence' => 'Published steps cannot be edited.',
        ]);

    expect($intro->fresh()->title)->toBe('Intro');
});

it('exposes version numbers and an unstarted count that matches migration', function () {
    [$user, $mailbox, $campaign] = versionedCampaign();
    $started = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Queued,
        'first_sent_at' => now(),
        'email_address' => 'started@example.test',
        'email_normalized' => 'started@example.test',
    ]);
    $unstarted = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Queued,
        'email_address' => 'waiting@example.test',
        'email_normalized' => 'waiting@example.test',
    ]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Replied,
        'email_address' => 'replied@example.test',
        'email_normalized' => 'replied@example.test',
    ]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Removed,
        'email_address' => 'removed@example.test',
        'email_normalized' => 'removed@example.test',
    ]);

    $launch = app(LaunchCampaignService::class);
    $launch->handle($campaign->fresh(), $user);
    $launch->pause($campaign->fresh());
    app(SequenceStepEditor::class)->add($campaign->fresh(), 'Bump');

    $count = app(PublishSequenceVersion::class)->unstartedRecipients($campaign->fresh())->count();

    $this->actingAs($user)
        ->get(route('campaigns.show', $campaign))
        ->assertInertia(fn (Assert $page) => $page
            ->where('campaign.sequence.draft_version', 2)
            ->where('campaign.sequence.published_version', 1)
            ->where('campaign.sequence.versions_count', 2)
            ->where('campaign.sequence.unstarted_count', $count)
            ->where('campaign.sequence.unstarted_count', 1));

    $launch->resume($campaign->fresh(), $user);

    expect($started->fresh()->sequenceVersion->version)->toBe(1)
        ->and($unstarted->fresh()->sequenceVersion->version)->toBe(2)
        ->and($count)->toBe(1);

    $this->actingAs($user)
        ->get(route('campaigns.show', $campaign))
        ->assertInertia(fn (Assert $page) => $page
            ->where('campaign.people', fn ($people): bool => collect($people)->contains(
                fn (array $person): bool => $person['email'] === 'started@example.test'
                    && $person['sequence_version'] === 1,
            ) && collect($people)->contains(
                fn (array $person): bool => $person['email'] === 'waiting@example.test'
                    && $person['sequence_version'] === 2,
            )));
});
