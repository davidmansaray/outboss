<?php

use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Gtm\Services\CreateOffer;
use App\Models\CampaignComposition;
use App\Models\ConversionDefinition;
use App\Models\Offer;
use App\Models\OfferVersionReviewEvent;
use App\Models\User;
use Illuminate\Support\Str;

function setupOffer(User $owner): Offer
{
    return app(CreateOffer::class)->handle($owner->currentWorkspace(), $owner, [
        'name' => 'Discovery consultation', 'promise' => 'Review your outreach process',
        'target_audience' => 'Agency owners', 'desired_outcome' => 'Identify improvements',
        'call_to_action' => 'Book a consultation', 'notes' => '',
    ], (string) Str::uuid());
}

function consultationInput(Offer $offer, string $definition): array
{
    return [
        'name' => 'Discovery consultation', 'promise' => 'Review your outreach process',
        'target_audience' => 'Agency owners', 'desired_outcome' => 'Identify improvements',
        'problem' => 'Unclear outbound process', 'value' => 'A practical action list', 'proof' => '',
        'commitment' => 'Book a 30-minute consultation', 'destination_url' => 'https://example.com/book',
        'acceptance_version_id' => $definition, 'approved_claims' => ['A 30-minute review of your outreach process'],
        'commercial_approved' => true, 'commercial_notes' => 'Free, no purchase required.',
        'expected_lock_version' => $offer->lock_version, 'client_operation_id' => (string) Str::uuid(),
    ];
}

it('preserves a scoped return to campaign across offer setup actions without changing its selection', function () {
    $owner = User::factory()->create();
    $offer = setupOffer($owner);
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Human setup');
    $url = '/offers/'.$offer->id.'/setup?from_campaign='.$campaign->id;
    $this->actingAs($owner)->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('return_campaign.id', $campaign->id)->where('return_campaign.name', 'Human setup'));
    $this->post('/offers/'.$offer->id.'/setup/measurement?from_campaign='.$campaign->id)->assertRedirect($url);
    $definition = ConversionDefinition::query()->where('workspace_id', $offer->workspace_id)->sole();
    $this->patch($url, consultationInput($offer, $definition->current_version_id))->assertSessionHasNoErrors()->assertRedirect($url);
    $offer->refresh();
    $this->post('/offers/'.$offer->id.'/setup/review?from_campaign='.$campaign->id, [
        'version_id' => $offer->current_version_id, 'lock_version' => $offer->lock_version,
        'action' => 'check', 'operation_id' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors()->assertRedirect($url);
    expect(CampaignComposition::query()->where('campaign_id', $campaign->id)->sole()->offer_version_id)->toBeNull();
    $other = User::factory()->create();
    $foreign = app(CreateCampaignService::class)->handle($other, 'Private');
    $this->get('/offers/'.$offer->id.'/setup?from_campaign='.$foreign->id)->assertNotFound();
    $this->post('/offers/'.$offer->id.'/setup/measurement?from_campaign='.$foreign->id)->assertNotFound();
});

it('completes operational setup review and activation through human web actions', function () {
    $owner = User::factory()->create();
    $offer = setupOffer($owner);
    $this->actingAs($owner)->get('/offers/'.$offer->id.'/setup')->assertOk();
    $this->post('/offers/'.$offer->id.'/setup/measurement')->assertRedirect();
    $definition = ConversionDefinition::query()->where('workspace_id', $offer->workspace_id)->sole();
    $this->patch('/offers/'.$offer->id.'/setup', consultationInput($offer, $definition->current_version_id))
        ->assertSessionHasNoErrors()->assertRedirect();
    foreach (['check', 'submit', 'approve', 'activate'] as $action) {
        $offer->refresh();
        $this->post('/offers/'.$offer->id.'/setup/review', [
            'version_id' => $offer->current_version_id, 'lock_version' => $offer->lock_version,
            'action' => $action, 'operation_id' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors()->assertRedirect();
    }
    expect($offer->fresh()->status->value)->toBe('active');
    expect($offer->fresh()->currentVersion->payload['fulfilment']['mode'])->toBe('external_destination');
    $offer->refresh();
    $activeVersion = $offer->current_version_id;
    $this->patch('/offers/'.$offer->id.'/setup', array_replace(
        consultationInput($offer, $definition->current_version_id), ['promise' => 'A changed promise'],
    ))->assertSessionHasErrors('status');
    expect($offer->fresh()->current_version_id)->toBe($activeVersion);
});

it('allows members to inspect but not configure or approve the contract', function () {
    $owner = User::factory()->create();
    $offer = setupOffer($owner);
    $member = User::factory()->create();
    $owner->currentWorkspace()->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
    $this->actingAs($member)->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $offer->workspace_id]);
    $this->get('/offers/'.$offer->id.'/setup')->assertOk();
    $this->patch('/offers/'.$offer->id.'/setup', [])->assertForbidden();
    $this->post('/offers/'.$offer->id.'/setup/measurement')->assertForbidden();
    $this->post('/offers/'.$offer->id.'/setup/review', [])->assertForbidden();
    expect(OfferVersionReviewEvent::query()->count())->toBe(0);
});

it('rejects stale review without approving a different version', function () {
    $owner = User::factory()->create();
    $offer = setupOffer($owner);
    $this->actingAs($owner)->post('/offers/'.$offer->id.'/setup/review', [
        'version_id' => (string) Str::uuid(), 'lock_version' => $offer->lock_version,
        'action' => 'approve', 'operation_id' => (string) Str::uuid(),
    ])->assertSessionHasErrors('offer');
    expect(OfferVersionReviewEvent::query()->count())->toBe(0);
});

it('scopes setup and acceptance definitions to the current workspace', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $offer = setupOffer($owner);
    $foreign = setupOffer($other);
    $this->actingAs($other)->post('/offers/'.$foreign->id.'/setup/measurement')->assertRedirect();
    $definition = ConversionDefinition::query()->where('workspace_id', $foreign->workspace_id)->sole();
    $this->actingAs($owner)->get('/offers/'.$foreign->id.'/setup')->assertNotFound();
    $this->patch('/offers/'.$offer->id.'/setup', consultationInput($offer, $definition->current_version_id))
        ->assertSessionHasErrors('acceptance_version_id');
    expect($offer->fresh()->lock_version)->toBe($offer->lock_version);
});
