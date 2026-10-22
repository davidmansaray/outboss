<?php

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\CampaignComposition;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\User;

it('rejects campaign list requests without a token', function () {
    $this->getJson('/api/v1/campaigns')
        ->assertUnauthorized()
        ->assertJsonPath('error.type', 'unauthenticated');
});

it('rejects campaign list requests when the token lacks the scope', function () {
    $user = User::factory()->create();

    $this->withToken(apiToken($user, [TokenScope::PeopleRead->value]))
        ->getJson('/api/v1/campaigns')
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');
});

it('lists campaigns for a token with campaigns:read and paginates', function () {
    $user = User::factory()->create();
    Campaign::factory()->create(['user_id' => $user->id, 'name' => 'Alpha', 'status' => CampaignStatus::Draft]);
    Campaign::factory()->create(['user_id' => $user->id, 'name' => 'Beta', 'status' => CampaignStatus::Paused]);

    $this->withToken(apiToken($user, [TokenScope::CampaignsRead->value]))
        ->getJson('/api/v1/campaigns?per_page=1')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonCount(1, 'data');
});

it('filters campaigns by status', function () {
    $user = User::factory()->create();
    Campaign::factory()->create(['user_id' => $user->id, 'status' => CampaignStatus::Draft]);
    Campaign::factory()->create(['user_id' => $user->id, 'status' => CampaignStatus::Paused]);

    $this->withToken(apiToken($user, [TokenScope::CampaignsRead->value]))
        ->getJson('/api/v1/campaigns?status=paused')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.status', 'paused');
});

it('creates, shows, and updates a campaign', function () {
    $user = User::factory()->create();
    $token = apiToken($user, [TokenScope::CampaignsRead->value, TokenScope::CampaignsWrite->value]);

    $created = $this->withToken($token)
        ->postJson('/api/v1/campaigns', ['name' => 'Q3 founders'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Q3 founders')
        ->assertJsonPath('data.status', 'draft');

    $id = $created->json('data.id');
    expect(CampaignComposition::query()->where('campaign_id', $id)->sole()->revision)->toBe(0);

    $this->withToken($token)
        ->getJson('/api/v1/campaigns/'.$id)
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.steps.0.title', 'Intro');

    $this->withToken($token)
        ->patchJson('/api/v1/campaigns/'.$id, ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');
});

it('returns a validation error shape when creating a campaign without a name', function () {
    $user = User::factory()->create();

    $this->withToken(apiToken($user, [TokenScope::CampaignsWrite->value]))
        ->postJson('/api/v1/campaigns', [])
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'validation_error')
        ->assertJsonStructure(['error' => ['type', 'message', 'errors']]);
});

it('hides another workspace campaign', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $owner->id]);

    $this->withToken(apiToken($intruder, [TokenScope::CampaignsRead->value]))
        ->getJson('/api/v1/campaigns/'.$campaign->id)
        ->assertForbidden()
        ->assertJsonPath('error.type', 'forbidden');
});

it('reports launch readiness and launches a ready campaign', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Queued,
    ]);
    $token = apiToken($user, [
        TokenScope::CampaignsRead->value,
        TokenScope::CampaignsLaunch->value,
    ]);

    $this->withToken($token)
        ->getJson('/api/v1/campaigns/'.$campaign->id.'/launch-readiness')
        ->assertOk()
        ->assertJsonPath('ready', true);

    $this->withToken($token)
        ->postJson('/api/v1/campaigns/'.$campaign->id.'/launch')
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Active);
});

it('pauses an active campaign with campaigns:write', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create([
        'user_id' => $user->id,
        'status' => CampaignStatus::Active,
        'launched_at' => now(),
    ]);

    $this->withToken(apiToken($user, [TokenScope::CampaignsWrite->value]))
        ->postJson('/api/v1/campaigns/'.$campaign->id.'/pause')
        ->assertOk()
        ->assertJsonPath('data.status', 'paused');
});
