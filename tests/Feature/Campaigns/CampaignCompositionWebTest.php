<?php

use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Models\CampaignComposition;
use App\Models\ExecutionAuthorityScope;
use App\Models\Objective;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function compositionWebInput(array $overrides = []): array
{
    return array_replace([
        'action' => 'select', 'objective_goal_version_id' => null, 'offer_version_id' => null,
        'expected_revision' => 0, 'operation_id' => (string) Str::uuid(),
    ], $overrides);
}

it('creates and shares a finite allowance without coupling campaign targets to Objectives', function () {
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'First');
    $input = compositionWebInput([
        'action' => 'create_scope', 'name' => 'Shared allowance', 'target_contribution_count' => 5,
        'authority' => ['starts_at' => now()->toAtomString(), 'ends_at' => now()->addDays(7)->toAtomString(),
            'maximum_people' => 10, 'maximum_accounts' => 10, 'maximum_messages' => 20,
            'maximum_ai_cost_micros' => 0, 'approval_required' => true],
    ]);
    $url = '/campaigns/'.$campaign->id.'/composition';
    $this->actingAs($owner)->post($url, $input)->assertSessionHasNoErrors()->assertRedirect();
    $this->post($url, $input)->assertSessionHasNoErrors();
    $scope = ExecutionAuthorityScope::query()->sole();
    $this->get('/campaigns/'.$campaign->id)->assertInertia(fn (Assert $page) => $page
        ->where('composition.execution_authority_scope_id', $scope->id)
        ->where('composition.target_contribution_count', 5)->has('composition.execution_scopes', 1));
    $second = app(CreateCampaignService::class)->handle($owner, 'Second');
    $this->post('/campaigns/'.$second->id.'/composition', compositionWebInput([
        'execution_authority_scope_id' => $scope->id, 'target_contribution_count' => 8,
    ]))->assertSessionHasNoErrors();
    expect(ExecutionAuthorityScope::query()->count())->toBe(1);
    expect(Objective::query()->count())->toBe(0);
    expect($campaign->fresh()->launched_at)->toBeNull();
    $this->post($url, array_replace($input, ['operation_id' => (string) Str::uuid(), 'name' => 'Stale']))
        ->assertSessionHasErrors('expected_revision');
    expect(ExecutionAuthorityScope::query()->count())->toBe(1);
    $other = User::factory()->create();
    $foreign = app(CreateCampaignService::class)->handle($other, 'Foreign');
    $this->actingAs($other)->get('/campaigns/'.$foreign->id)->assertInertia(fn (Assert $page) => $page->has('composition.execution_scopes', 0));
    $this->post('/campaigns/'.$foreign->id.'/composition', compositionWebInput(['execution_authority_scope_id' => $scope->id]))
        ->assertSessionHasErrors('execution_authority_scope_id');
});

it('creates an evergreen Objective inline without a target or deadline', function () {
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Evergreen campaign');
    $this->actingAs($owner)->post('/campaigns/'.$campaign->id.'/composition', compositionWebInput([
        'action' => 'create_goal', 'name' => 'Book meetings',
    ]))->assertSessionHasNoErrors()->assertRedirect();
    $goal = Objective::query()->sole()->currentGoalVersion;
    expect($goal->target_count)->toBeNull()->and($goal->deadline)->toBeNull();
    $this->get('/campaigns/'.$campaign->id)->assertInertia(fn (Assert $page) => $page->where('composition.goals.0.measurement_configured', false));
    expect(CampaignComposition::query()->orderByDesc('revision')->first()->objective_goal_version_id)->toBe($goal->id);
});

it('creates independent library records inline and returns their selected versions', function () {
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Human draft');
    $url = '/campaigns/'.$campaign->id.'/composition';
    $this->actingAs($owner)->post($url, compositionWebInput([
        'action' => 'create_goal', 'name' => 'Shared meetings', 'target_count' => 15,
    ]))->assertSessionHasNoErrors()->assertRedirect('/campaigns/'.$campaign->id);
    $goal = Objective::query()->sole();
    $this->post($url, compositionWebInput([
        'action' => 'create_offer', 'name' => 'Discovery consultation', 'promise' => 'Review your process',
        'expected_revision' => 1, 'objective_goal_version_id' => $goal->current_goal_version_id,
    ]))->assertSessionHasNoErrors()->assertRedirect();
    $offer = Offer::query()->sole();
    $this->get('/campaigns/'.$campaign->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('campaigns/Show')->where('composition.revision', 2)
        ->where('composition.objective_goal_version_id', $goal->current_goal_version_id)
        ->where('composition.offer_version_id', $offer->current_version_id)
        ->where('composition.goals.0.target_count', 15)->where('composition.offers.0.status', 'draft'));
    expect($goal->current_version_id)->toBeNull();
    expect($campaign->fresh()->launched_at)->toBeNull();
});

it('exposes selected Objective availability without replacing its saved version', function (string $status) {
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Objective availability');
    $this->actingAs($owner)->post('/campaigns/'.$campaign->id.'/composition', compositionWebInput([
        'action' => 'create_goal', 'name' => 'Meetings',
    ]))->assertSessionHasNoErrors();
    $objective = Objective::query()->sole();
    DB::table('objectives')->where('id', $objective->id)->update(['status' => $status]);

    $this->get('/campaigns/'.$campaign->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('composition.objective_goal_version_id', $objective->current_goal_version_id)
        ->where('composition.goals.0.id', $objective->current_goal_version_id)
        ->where('composition.goals.0.status', $status));
})->with(['draft', 'ready', 'active', 'paused', 'blocked', 'completed', 'cancelled', 'expired']);

it('displays legacy Offer versions without a name in their payload', function () {
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Legacy Offer campaign');
    $this->actingAs($owner)->post('/campaigns/'.$campaign->id.'/composition', compositionWebInput([
        'action' => 'create_offer', 'name' => 'Reusable consultation', 'promise' => 'Review your process',
    ]))->assertSessionHasNoErrors();
    $offer = Offer::query()->sole();
    $version = $offer->currentVersion;
    $payload = $version->payload;
    unset($payload['name']);
    DB::table('offer_versions')->where('id', $version->id)
        ->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    $this->get('/campaigns/'.$campaign->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('composition.offers.0.name', 'Untitled Offer'));
});

it('rolls back inline creation when campaign selection is stale and replays safely', function () {
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Human draft');
    $url = '/campaigns/'.$campaign->id.'/composition';
    $input = compositionWebInput(['action' => 'create_goal', 'name' => 'Meetings', 'target_count' => 10]);
    $this->actingAs($owner)->post($url, $input)->assertSessionHasNoErrors();
    $this->post($url, $input)->assertSessionHasNoErrors();
    expect(Objective::query()->count())->toBe(1);
    expect(CampaignComposition::query()->where('revision', '>', 0)->count())->toBe(1);
    $this->post($url, compositionWebInput(['action' => 'create_offer', 'name' => 'Uncommitted offer']))
        ->assertSessionHasErrors('expected_revision');
    expect(Offer::query()->count())->toBe(0);
});

it('does not expose foreign workspace selector options', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $foreign = app(CreateCampaignService::class)->handle($other, 'Other');
    $this->actingAs($other)->post('/campaigns/'.$foreign->id.'/composition', compositionWebInput([
        'action' => 'create_goal', 'name' => 'Private goal', 'target_count' => 10,
    ]))->assertSessionHasNoErrors();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Mine');
    $this->actingAs($owner)->get('/campaigns/'.$campaign->id)->assertInertia(fn (Assert $page) => $page
        ->has('composition.goals', 0)->has('composition.offers', 0));
});
