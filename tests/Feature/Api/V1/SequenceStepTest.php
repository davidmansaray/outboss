<?php

use App\Domain\Api\TokenScope;
use App\Models\Campaign;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Str;

it('rejects sequence step writes without a token', function () {
    $this->postJson('/api/v1/campaigns/1/steps', [])
        ->assertUnauthorized()
        ->assertJsonPath('error.type', 'unauthenticated');
});

it('rejects sequence step writes when the token lacks campaigns:write', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);

    $this->withToken(apiToken($user, [TokenScope::CampaignsRead->value]))
        ->postJson('/api/v1/campaigns/'.$campaign->id.'/steps', ['title' => 'Follow-up'])
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');
});

it('adds, updates, previews, and deletes a sequence step', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $token = apiToken($user, [TokenScope::CampaignsRead->value, TokenScope::CampaignsWrite->value]);

    $created = $this->withToken($token)
        ->postJson('/api/v1/campaigns/'.$campaign->id.'/steps', ['title' => 'Follow-up'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Follow-up');

    $stepId = $created->json('data.id');

    $this->withToken($token)
        ->patchJson('/api/v1/sequence-steps/'.$stepId, [
            'body_template' => 'Bumping this, {{first_name}}.',
        ])
        ->assertOk()
        ->assertJsonPath('data.body', 'Bumping this, {{first_name}}.');

    $this->withToken($token)
        ->getJson('/api/v1/sequence-steps/'.$stepId.'/preview')
        ->assertOk()
        ->assertJsonStructure(['subject', 'body', 'to']);

    $this->withToken($token)
        ->deleteJson('/api/v1/sequence-steps/'.$stepId)
        ->assertNoContent();
});

it('hides another workspace sequence step', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $owner->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();

    $this->withToken(apiToken($intruder, [TokenScope::CampaignsRead->value]))
        ->getJson('/api/v1/sequence-steps/'.$step->id.'/preview')
        ->assertForbidden();
});

it('sends a test email when the token has inbox:send', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();

    $this->withToken(apiToken($user, [TokenScope::InboxSend->value]))
        ->postJson('/api/v1/sequence-steps/'.$step->id.'/test', [
            'mailbox_id' => $mailbox->id,
            'to' => 'maya@example.test',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertOk()
        ->assertJsonPath('sent', true);
});

it('rejects a test send when the token only has campaigns:write', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();

    $this->withToken(apiToken($user, [TokenScope::CampaignsWrite->value]))
        ->postJson('/api/v1/sequence-steps/'.$step->id.'/test', [
            'mailbox_id' => 1,
            'to' => 'maya@example.test',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');
});

it('returns validation details for a malformed enabled body template', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    $step->forceFill(['body_template' => '{Hi|Hello', 'body_spintax_enabled' => true])->save();

    $this->withToken(apiToken($user, [TokenScope::InboxSend->value]))
        ->postJson('/api/v1/sequence-steps/'.$step->id.'/test', [
            'mailbox_id' => $mailbox->id,
            'to' => 'maya@example.test',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertUnprocessable()
        ->assertJsonPath(
            'error.errors.body_template.0',
            'Email 1 body: Unclosed variation. Add a closing } or escape the opening brace as \\{.',
        );
});

it('returns a subject-specific validation error for a malformed enabled subject', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    $step->forceFill([
        'subject_template' => '{Question|Hello',
        'subject_spintax_enabled' => true,
    ])->save();

    $this->withToken(apiToken($user, [TokenScope::InboxSend->value]))
        ->postJson('/api/v1/sequence-steps/'.$step->id.'/test', [
            'mailbox_id' => $mailbox->id,
            'to' => 'maya@example.test',
            'client_operation_id' => (string) Str::uuid(),
        ])
        ->assertUnprocessable()
        ->assertJsonPath(
            'error.errors.subject_template.0',
            'Email 1 subject: Unclosed variation. Add a closing } or escape the opening brace as \\{.',
        );
});
