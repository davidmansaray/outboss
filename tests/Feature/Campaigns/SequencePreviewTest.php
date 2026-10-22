<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SequenceSendAs;
use App\Domain\Campaigns\Services\RenderSequenceMessage;
use App\Domain\Campaigns\Services\ResolveStepSubject;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\SequenceStep;
use App\Models\User;

it('previews an enrolled recipient with production rendering', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    $step->forceFill([
        'subject_template' => 'Hello {{first_name}} at {{company}}',
        'body_template' => 'Hi {{first_name}} & team',
    ])->save();
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $campaign->workspace_id,
        'status' => RecipientStatus::Queued,
        'first_name' => null,
        'company' => null,
        'email_address' => 'maya@example.test',
        'email_normalized' => 'maya@example.test',
    ]);

    $expected = app(RenderSequenceMessage::class)->handle($step->fresh(), $recipient, test: false);

    $this->actingAs($user)
        ->getJson(route('sequence-steps.preview', $step).'?recipient_id='.$recipient->id)
        ->assertOk()
        ->assertJson([
            'subject' => $expected['subject'],
            'body' => $expected['body'],
            'to' => 'maya@example.test',
            'recipient_id' => $recipient->id,
        ])
        ->assertJsonPath('subject', 'Hello there at your team');
});

it('escapes snapshot values and never prefixes a test marker', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    $step->forceFill([
        'subject_template' => 'Hello {{first_name}} at {{company}}',
        'body_template' => 'Hi {{first_name}}',
    ])->save();
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $campaign->workspace_id,
        'first_name' => '<Maya>',
        'company' => 'Stripe & Co',
        'email_address' => 'maya@example.test',
        'email_normalized' => 'maya@example.test',
    ]);

    $this->actingAs($user)
        ->getJson(route('sequence-steps.preview', $step).'?recipient_id='.$recipient->id)
        ->assertOk()
        ->assertJson([
            'subject' => 'Hello &lt;Maya&gt; at Stripe &amp; Co',
            'body' => 'Hi &lt;Maya&gt;',
            'recipient_id' => $recipient->id,
        ])
        ->assertJsonMissing(['subject' => '[TEST] Hello &lt;Maya&gt; at Stripe &amp; Co']);
});

it('inherits the nearest new-thread subject for a reply preview', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $intro = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    $intro->forceFill([
        'subject_template' => '{Question|Quick question|One question} about {{company}}',
        'subject_spintax_enabled' => true,
    ])->save();
    $reply = SequenceStep::factory()->create([
        'sequence_version_id' => $intro->sequence_version_id,
        'position' => 2,
        'title' => 'Follow-up',
        'send_as' => SequenceSendAs::Reply,
        'subject_template' => null,
        'body_template' => 'Bumping this.',
    ]);
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $campaign->workspace_id,
        'first_name' => 'Maya',
        'company' => 'Stripe',
        'email_address' => 'maya@example.test',
        'email_normalized' => 'maya@example.test',
    ]);

    $reply = $reply->fresh('version');
    $subject = app(ResolveStepSubject::class)->handle($reply);
    $expected = app(RenderSequenceMessage::class)->handle($intro->fresh(), $recipient);

    $this->actingAs($user)
        ->getJson(route('sequence-steps.preview', $reply).'?recipient_id='.$recipient->id)
        ->assertOk()
        ->assertJson([
            'subject' => $expected['subject'],
            'body' => 'Bumping this.',
        ])
        ->assertJsonPath('subject', fn (string $rendered): bool => in_array($rendered, [
            'Question about Stripe',
            'Quick question about Stripe',
            'One question about Stripe',
        ], true));

    expect($subject)->toBe('{Question|Quick question|One question} about {{company}}');
});

it('renders Jane and Acme when no recipient is selected', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    $step->forceFill([
        'subject_template' => 'Hello {{first_name}} at {{company}}',
        'body_template' => 'Hi {{first_name}}',
    ])->save();

    $this->actingAs($user)
        ->getJson(route('sequence-steps.preview', $step))
        ->assertOk()
        ->assertJson([
            'subject' => 'Hello Jane at Acme',
            'body' => 'Hi Jane',
            'to' => 'jane@example.com',
            'recipient_id' => null,
        ]);
});

it('does not reveal recipients from another campaign or workspace', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    $other = User::factory()->create();
    $foreignCampaign = Campaign::factory()->create(['user_id' => $other->id]);
    $foreignRecipient = CampaignRecipient::factory()->create([
        'campaign_id' => $foreignCampaign->id,
        'workspace_id' => $foreignCampaign->workspace_id,
        'email_address' => 'foreign@example.test',
        'email_normalized' => 'foreign@example.test',
    ]);

    $this->actingAs($user)
        ->getJson(route('sequence-steps.preview', $step).'?recipient_id='.$foreignRecipient->id)
        ->assertNotFound();
});

it('returns 422 when a legacy draft contains an unknown variable', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    $step->forceFill(['body_template' => 'Hi {{last_name}}'])->save();

    $this->actingAs($user)
        ->getJson(route('sequence-steps.preview', $step))
        ->assertStatus(422)
        ->assertJson([
            'message' => 'Email 1 body: Unknown variable {{last_name}}',
            'errors' => ['body_template' => ['Email 1 body: Unknown variable {{last_name}}']],
        ]);
});

it('keeps a recipient variation stable across repeated previews', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    $step->forceFill([
        'subject_template' => '{Question|Quick question} about {{company}}',
        'body_template' => '{Hi|Hello|Hey} {{first_name}}',
        'subject_spintax_enabled' => true,
        'body_spintax_enabled' => true,
    ])->save();
    $recipient = CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'workspace_id' => $campaign->workspace_id,
        'first_name' => 'Maya',
        'company' => 'Stripe',
        'email_address' => 'maya@example.test',
        'email_normalized' => 'maya@example.test',
    ]);

    $url = route('sequence-steps.preview', $step).'?recipient_id='.$recipient->id;
    $first = $this->actingAs($user)->getJson($url)->assertOk()->json();
    $second = $this->actingAs($user)->getJson($url)->assertOk()->json();

    expect($second)->toBe($first)
        ->and($first['subject'])->not->toContain('{')
        ->and($first['body'])->not->toContain('{');
});

it('previews malformed variation-like text literally for a legacy draft', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    $step->forceFill(['body_template' => '{Hi|Hello {{first_name}}'])->save();

    $this->actingAs($user)
        ->getJson(route('sequence-steps.preview', $step))
        ->assertOk()
        ->assertJsonPath('body', '{Hi|Hello Jane');
});
