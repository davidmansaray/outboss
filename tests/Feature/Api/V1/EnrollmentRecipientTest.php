<?php

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\Person;
use App\Models\User;

it('rejects enrollable people without a token', function () {
    $this->getJson('/api/v1/campaigns/1/enrollable-people')
        ->assertUnauthorized()
        ->assertJsonPath('error.type', 'unauthenticated');
});

it('rejects enrollments when the token lacks campaigns:write', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    $this->withToken(apiToken($user, [TokenScope::CampaignsRead->value]))
        ->postJson('/api/v1/campaigns/'.$campaign->id.'/enrollments', ['person_ids' => [1]])
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');
});

it('lists enrollable people and enrols them', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $person = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'full_name' => 'Maya Chen',
        'email_address' => 'maya@stripe.com',
        'email_normalized' => 'maya@stripe.com',
    ]);
    $token = apiToken($user, [TokenScope::CampaignsRead->value, TokenScope::CampaignsWrite->value]);

    $this->withToken($token)
        ->getJson('/api/v1/campaigns/'.$campaign->id.'/enrollable-people')
        ->assertOk()
        ->assertJsonPath('data.0.id', $person->id)
        ->assertJsonPath('data.0.enrollable', true)
        ->assertJsonStructure(['data', 'meta']);

    $this->withToken($token)
        ->postJson('/api/v1/campaigns/'.$campaign->id.'/enrollments', [
            'person_ids' => [$person->id],
        ])
        ->assertCreated()
        ->assertJsonPath('enrolled', 1);

    expect(CampaignRecipient::query()->where('person_id', $person->id)->exists())->toBeTrue();
});

it('replays an enrolment when the same Idempotency-Key is sent twice', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $first = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'email_address' => 'one@acme.test',
        'email_normalized' => 'one@acme.test',
    ]);
    $token = apiToken($user, [TokenScope::CampaignsWrite->value]);

    $firstResponse = $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'enrol-once'])
        ->postJson('/api/v1/campaigns/'.$campaign->id.'/enrollments', [
            'person_ids' => [$first->id],
        ])
        ->assertCreated()
        ->assertJsonPath('enrolled', 1);

    $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'enrol-once'])
        ->postJson('/api/v1/campaigns/'.$campaign->id.'/enrollments', [
            'person_ids' => [$first->id],
        ])
        ->assertCreated()
        ->assertExactJson($firstResponse->json());

    expect(CampaignRecipient::query()->where('campaign_id', $campaign->id)->count())->toBe(1);
});

it('rejects reusing an Idempotency-Key with a different body or target', function () {
    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $firstCampaign = Campaign::factory()->create(['user_id' => $user->id]);
    $secondCampaign = Campaign::factory()->create(['user_id' => $user->id]);
    $firstCampaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $secondCampaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    $first = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'email_address' => 'first@acme.test',
        'email_normalized' => 'first@acme.test',
    ]);
    $second = Person::factory()->create([
        'workspace_id' => $workspace->id,
        'email_address' => 'second@acme.test',
        'email_normalized' => 'second@acme.test',
    ]);
    $token = apiToken($user, [TokenScope::CampaignsWrite->value]);

    $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'enrol-conflict'])
        ->postJson('/api/v1/campaigns/'.$firstCampaign->id.'/enrollments', [
            'person_ids' => [$first->id],
        ])
        ->assertCreated();

    $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'enrol-conflict'])
        ->postJson('/api/v1/campaigns/'.$firstCampaign->id.'/enrollments', [
            'person_ids' => [$second->id],
        ])
        ->assertConflict()
        ->assertJsonPath('error.type', 'idempotency_conflict');

    $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'enrol-conflict'])
        ->postJson('/api/v1/campaigns/'.$secondCampaign->id.'/enrollments', [
            'person_ids' => [$first->id],
        ])
        ->assertConflict()
        ->assertJsonPath('error.type', 'idempotency_conflict');

    expect(CampaignRecipient::query()->where('campaign_id', $firstCampaign->id)->count())->toBe(1)
        ->and(CampaignRecipient::query()->where('campaign_id', $secondCampaign->id)->count())->toBe(0);
});

it('returns a validation error when enrolments omit person_ids', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    $this->withToken(apiToken($user, [TokenScope::CampaignsWrite->value]))
        ->postJson('/api/v1/campaigns/'.$campaign->id.'/enrollments', [])
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'validation_error');
});

it('lists and pauses recipients', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Active,
        'next_send_at' => now()->addHour(),
    ]);
    $token = apiToken($user, [TokenScope::CampaignsRead->value, TokenScope::CampaignsWrite->value]);

    $this->withToken($token)
        ->getJson('/api/v1/campaigns/'.$campaign->id.'/recipients?status=active')
        ->assertOk()
        ->assertJsonPath('data.0.id', $recipient->id)
        ->assertJsonPath('meta.total', 1);

    $this->withToken($token)
        ->patchJson('/api/v1/campaign-recipients/'.$recipient->id, ['action' => 'pause'])
        ->assertOk()
        ->assertJsonPath('data.lifecycle', 'paused');
});

it('hides another workspace recipient', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $owner->id]);
    $campaign = Campaign::factory()->create(['user_id' => $owner->id]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
    ]);

    $this->withToken(apiToken($intruder, [TokenScope::CampaignsWrite->value]))
        ->deleteJson('/api/v1/campaign-recipients/'.$recipient->id)
        ->assertForbidden();
});
