<?php

use App\Domain\Gtm\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the current workspace offers and their current versions', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $offer = Offer::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => OfferStatus::Active,
    ]);
    $version = OfferVersion::factory()->create([
        'offer_id' => $offer->id,
        'status_snapshot' => OfferStatus::Active,
        'payload' => [
            'name' => 'Founder signal sprint',
            'promise' => 'Find the next qualified conversation.',
            'target_audience' => 'Founder-led B2B teams',
            'desired_outcome' => 'Qualified conversations',
            'call_to_action' => 'Review a shortlist',
            'notes' => 'Keep the first cohort narrow.',
        ],
    ]);
    $offer->forceFill(['current_version_id' => $version->id])->save();

    $this->actingAs($owner)
        ->get(route('offers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('offers/Index')
            ->has('offers', 1)
            ->where('offers.0.uuid', $offer->id)
            ->where('offers.0.name', 'Founder signal sprint')
            ->where('offers.0.promise', 'Find the next qualified conversation.')
            ->where('offers.0.status', 'active')
            ->where('offers.0.current_version_id', $version->id)
            ->where('offers.0.current_version.uuid', $version->id)
            ->where('offers.0.current_version.status_snapshot', 'active')
            ->where('offers.0.version_count', 1)
            ->missing('offers.0.versions')
            ->where('offerPagination.has_more', false)
            ->where('offerCounts.all', 1)
            ->where('offerCounts.active', 1)
            ->where('offerFilter', 'all')
            ->where('isOwner', true));
});

it('cursor-paginates the Offer index and returns authoritative totals', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();

    Offer::factory()->count(27)->create(['workspace_id' => $workspace->id]);

    $first = $this->actingAs($owner)
        ->getJson(route('offers.index', ['per_page' => 10]))
        ->assertOk()
        ->assertJsonCount(10, 'offers')
        ->assertJsonPath('pagination.has_more', true)
        ->assertJsonPath('pagination.per_page', 10)
        ->assertJsonPath('counts.all', 27)
        ->assertJsonPath('counts.draft', 27);
    $cursor = $first->json('pagination.next_cursor');

    expect($cursor)->toBeString()->not->toBeEmpty();

    $second = $this->actingAs($owner)
        ->getJson(route('offers.index', ['per_page' => 10, 'cursor' => $cursor]))
        ->assertOk()
        ->assertJsonCount(10, 'offers');

    expect(collect($first->json('offers'))->pluck('uuid')->intersect(
        collect($second->json('offers'))->pluck('uuid'),
    ))->toBeEmpty();
});

it('filters before cursor pagination while keeping workspace totals authoritative', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $active = Offer::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => OfferStatus::Active,
    ]);
    DB::table('offers')->where('id', $active->id)->update([
        'updated_at' => now()->subDay(),
    ]);
    Offer::factory()->count(25)->create(['workspace_id' => $workspace->id]);

    $unfiltered = $this->actingAs($owner)
        ->getJson(route('offers.index'))
        ->assertOk()
        ->assertJsonCount(25, 'offers')
        ->assertJsonPath('pagination.has_more', true);

    expect(collect($unfiltered->json('offers'))->pluck('uuid'))->not->toContain($active->id);

    $this->actingAs($owner)
        ->getJson(route('offers.index', ['status' => 'active']))
        ->assertOk()
        ->assertJsonCount(1, 'offers')
        ->assertJsonPath('offers.0.uuid', $active->id)
        ->assertJsonPath('offers.0.status', 'active')
        ->assertJsonPath('pagination.has_more', false)
        ->assertJsonPath('counts.all', 26)
        ->assertJsonPath('counts.active', 1)
        ->assertJsonPath('filter', 'active');
});

it('includes an older workspace Offer needed by an overlay deep link', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $requested = Offer::factory()->create([
        'workspace_id' => $workspace->id,
    ]);
    DB::table('offers')->where('id', $requested->id)->update([
        'updated_at' => now()->subDay(),
    ]);
    Offer::factory()->count(25)->create(['workspace_id' => $workspace->id]);

    $this->actingAs($owner)
        ->get(route('offers.index', [
            'panel' => 'history',
            'offer' => $requested->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('offers', 26)
            ->where('offers.25.uuid', $requested->id)
            ->where('offerPagination.has_more', true)
            ->where('offerCounts.all', 26));
});

it('returns a scoped 404 for invalid or foreign Offer overlay deep links', function () {
    $owner = User::factory()->create();
    $foreignOwner = User::factory()->create();
    $foreignOffer = Offer::factory()->create([
        'workspace_id' => $foreignOwner->currentWorkspace()->id,
    ]);

    $this->actingAs($owner)
        ->get(route('offers.index', ['panel' => 'edit', 'offer' => 'not-a-uuid']))
        ->assertNotFound();
    $this->actingAs($owner)
        ->get(route('offers.index', ['panel' => 'history', 'offer' => $foreignOffer->id]))
        ->assertNotFound();
    $this->actingAs($owner)
        ->get(route('offers.index', ['panel' => 'history']))
        ->assertNotFound();
    $this->actingAs($owner)
        ->get(route('offers.index', ['panel' => 'unsupported']))
        ->assertNotFound();
});

it('keeps the edit panel without an Offer ID as the valid create deep link', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('offers.index', ['panel' => 'edit']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('offers/Index')
            ->has('offers', 0));
});

it('only renders offers from the active workspace for members', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $member = User::factory()->create();
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);

    $workspaceOffer = Offer::factory()->create(['workspace_id' => $workspace->id]);
    $workspaceVersion = OfferVersion::factory()->create(['offer_id' => $workspaceOffer->id]);
    $workspaceOffer->forceFill(['current_version_id' => $workspaceVersion->id])->save();

    $foreignWorkspace = $member->workspaces()
        ->wherePivot('role', WorkspaceMembership::ROLE_OWNER)
        ->where('workspaces.id', '!=', $workspace->id)
        ->firstOrFail();
    $foreignOffer = Offer::factory()->create(['workspace_id' => $foreignWorkspace->id]);
    $foreignVersion = OfferVersion::factory()->create(['offer_id' => $foreignOffer->id]);
    $foreignOffer->forceFill(['current_version_id' => $foreignVersion->id])->save();

    $this->actingAs($member)
        ->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id])
        ->get(route('offers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('offers/Index')
            ->has('offers', 1)
            ->where('offers.0.uuid', $workspaceOffer->id)
            ->where('isOwner', false));
});

it('requires authentication to view offers', function () {
    $this->get(route('offers.index'))->assertRedirect(route('login'));
});
