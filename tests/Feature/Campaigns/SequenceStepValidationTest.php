<?php

use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('rejects unsupported sequence variables on save', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();

    $this->actingAs($user)
        ->from(route('campaigns.show', $campaign))
        ->patch(route('sequence-steps.update', $step), [
            'body_template' => 'Hi {{last_name}}',
        ])
        ->assertRedirect(route('campaigns.show', $campaign))
        ->assertSessionHasErrors([
            'body_template' => 'Unknown variable {{last_name}}. Supported: {{first_name}}, {{company}}.',
        ]);

    expect($step->fresh()->body_template)->not->toBe('Hi {{last_name}}');
});

it('rejects a body over the save-time length limit', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();

    $this->actingAs($user)
        ->from(route('campaigns.show', $campaign))
        ->patch(route('sequence-steps.update', $step), [
            'body_template' => str_repeat('a', 10001),
        ])
        ->assertRedirect(route('campaigns.show', $campaign))
        ->assertSessionHasErrors('body_template');
});

it('saves supported variables', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();

    $this->actingAs($user)
        ->patch(route('sequence-steps.update', $step), [
            'body_template' => 'Hi {{first_name}} at {{company}}',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($step->fresh()->body_template)->toBe('Hi {{first_name}} at {{company}}')
        ->and($step->fresh()->body_spintax_enabled)->toBeFalse()
        ->and($step->fresh()->subject_spintax_enabled)->toBeFalse();
});

it('saves valid variations and rejects malformed ones', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();

    $this->actingAs($user)
        ->patch(route('sequence-steps.update', $step), [
            'body_template' => '{Hi|Hello|Hey} {{first_name}}',
            'body_spintax_enabled' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($step->fresh()->body_template)->toBe('{Hi|Hello|Hey} {{first_name}}')
        ->and($step->fresh()->body_spintax_enabled)->toBeTrue();

    $this->actingAs($user)
        ->from(route('campaigns.show', $campaign))
        ->patch(route('sequence-steps.update', $step), [
            'body_template' => '{Hi|Hello {{first_name}}',
        ])
        ->assertRedirect(route('campaigns.show', $campaign))
        ->assertSessionHasErrors([
            'body_template' => 'Unclosed variation. Add a closing } or escape the opening brace as \\{.',
        ]);

    expect($step->fresh()->body_template)->toBe('{Hi|Hello|Hey} {{first_name}}');
});

it('enables variations only for the explicitly selected template field', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    $step->forceFill(['subject_template' => 'Legacy {Question|Hello'])->save();

    $this->actingAs($user)
        ->patch(route('sequence-steps.update', $step), [
            'body_template' => '{Hi|Hello} {{first_name}}',
            'body_spintax_enabled' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $step->refresh();
    expect($step->subject_spintax_enabled)->toBeFalse()
        ->and($step->body_spintax_enabled)->toBeTrue();

    $this->actingAs($user)
        ->getJson(route('sequence-steps.preview', $step))
        ->assertOk()
        ->assertJsonPath('subject', 'Legacy {Question|Hello')
        ->assertJsonPath('body', fn (string $body): bool => in_array($body, ['Hi Jane', 'Hello Jane'], true));
});

it('keeps variations disabled when human copy is edited later', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();
    $step->forceFill(['subject_spintax_enabled' => true, 'body_spintax_enabled' => true])->save();
    $this->actingAs($user)->patch(route('sequence-steps.update', $step), [
        'subject_spintax_enabled' => false, 'body_spintax_enabled' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->patch(route('sequence-steps.update', $step), [
        'subject_template' => 'Hello {{first_name}}', 'body_template' => 'A question for {{company}}',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($step->fresh()->subject_spintax_enabled)->toBeFalse()
        ->and($step->fresh()->body_spintax_enabled)->toBeFalse();
});

it('supports a four day follow-up delay', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $step = $campaign->fresh('draftVersion.activeSteps')->draftVersion->activeSteps->first();

    $this->actingAs($user)
        ->patch(route('sequence-steps.update', $step), [
            'delay_minutes' => 4 * 24 * 60,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($step->fresh()->delay_minutes)->toBe(5760);
});

it('still blocks launch when the sequence structure is invalid', function () {
    $user = User::factory()->create();
    $mailbox = Mailbox::factory()->create(['user_id' => $user->id]);
    $campaign = Campaign::factory()->create(['user_id' => $user->id]);
    $campaign->mailboxes()->sync([$mailbox->id => ['position' => 1]]);
    CampaignRecipient::factory()->create([
        'campaign_id' => $campaign->id,
        'mailbox_id' => $mailbox->id,
        'status' => RecipientStatus::Queued,
    ]);
    $campaign->draftVersion->activeSteps->first()->forceFill([
        'body_template' => '',
    ])->save();

    expect(fn () => app(LaunchCampaignService::class)->handle($campaign->fresh(), $user))
        ->toThrow(ValidationException::class);
});
