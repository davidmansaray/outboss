<?php

use App\Domain\Gtm\Enums\OfferStatus;
use App\Models\GtmProfileReviewItem;
use App\Models\Offer;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the workspace GTM read surface without a feature gate', function () {

    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $profile = $workspace->gtmProfile()->firstOrFail();
    GtmProfileReviewItem::factory()->create([
        'gtm_profile_id' => $profile->id,
    ]);
    Offer::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => OfferStatus::Active,
    ]);

    expect(route('gtm-profile.show', absolute: false))->toBe('/settings/gtm-profile');

    $this->actingAs($user)
        ->get(route('gtm-profile.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gtm-profile/Show')
            ->where('profile.uuid', $profile->id)
            ->where('profile.lock_version', 0)
            ->where('profile.current_version_id', $profile->current_version_id)
            ->where('profile.payload.company.name.value', $workspace->name)
            ->has('profile.current_version')
            ->where('researchState.state', 'not_started')
            ->where('researchState.source_count', 0)
            ->where('researchState.website_url', null)
            ->where('researchState.stale', false)
            ->has('reviewItems', 1)
            ->where('reviewItems.0.status', 'open')
            ->missing('sources')
            ->missing('history')
            ->where('activeOffersCount', 1)
            ->where('isOwner', true));
});
