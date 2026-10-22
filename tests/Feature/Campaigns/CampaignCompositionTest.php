<?php

use App\Domain\Campaigns\Exceptions\GovernedCampaignLaunchRequired;
use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Campaigns\Services\GovernedCampaignExecutionGuard;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Domain\Campaigns\Services\SaveCampaignComposition;
use App\Domain\Gtm\Services\CreateOffer;
use App\Domain\Objectives\Enums\PolicyEvaluationStage;
use App\Domain\Objectives\Services\SaveObjectiveGoal;
use App\Models\CampaignComposition;
use App\Models\ObjectiveGoalVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function compositionInput(array $overrides = []): array
{
    return array_replace([
        'objective_goal_version_id' => null, 'offer_version_id' => null,
        'expected_revision' => 0, 'operation_id' => (string) Str::uuid(),
    ], $overrides);
}

it('reuses one goal and offer across independent campaign drafts without copying intent', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $goal = app(SaveObjectiveGoal::class)->handle($workspace, $owner, [
        'name' => 'Book meetings', 'measurement' => 'meeting_booked', 'target_count' => 10, 'creation_key' => 'shared',
    ]);
    $offer = app(CreateOffer::class)->handle($workspace, $owner, [
        'name' => 'Consultation', 'promise' => 'A review', 'target_audience' => 'Owners',
        'desired_outcome' => 'Next steps', 'call_to_action' => 'Book', 'notes' => '',
    ], (string) Str::uuid());
    $first = app(CreateCampaignService::class)->handle($owner, 'First audience');
    $second = app(CreateCampaignService::class)->handle($owner, 'Second audience');
    foreach ([$first, $second] as $campaign) {
        $saved = app(SaveCampaignComposition::class)->handle($campaign, $owner, compositionInput([
            'objective_goal_version_id' => $goal->current_goal_version_id, 'offer_version_id' => $offer->current_version_id,
        ]));
        expect($saved->revision)->toBe(1);
    }
    expect($first->draft_sequence_version_id)->not->toBe($second->draft_sequence_version_id);
    expect($goal->fresh()->currentGoalVersion->target_count)->toBe(10);
    expect(ObjectiveGoalVersion::query()->where('objective_id', $goal->id)->count())->toBe(1);
    expect(CampaignComposition::query()->where('revision', '>', 0)->count())->toBe(2);
});

it('deduplicates retries and preserves later selection revisions', function () {
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Draft');
    $service = app(SaveCampaignComposition::class);
    $input = compositionInput();
    $first = $service->handle($campaign, $owner, $input);
    $second = $service->handle($campaign, $owner, compositionInput(['expected_revision' => 1]));
    expect($service->handle($campaign, $owner, $input)->id)->toBe($first->id);
    expect(CampaignComposition::query()->orderByDesc('revision')->first()->id)->toBe($second->id);
    expect(fn () => $service->handle($campaign, $owner, compositionInput()))->toThrow(ValidationException::class);
    expect(fn () => $service->handle($campaign, $owner, array_replace($input, ['expected_revision' => 2])))
        ->toThrow(ValidationException::class);
    expect(fn () => $first->update(['revision' => 9]))->toThrow(LogicException::class);
});

it('blocks generic launch and every send checkpoint even for an empty composition', function () {
    $owner = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Draft');
    expect(CampaignComposition::query()->where('campaign_id', $campaign->id)->sole()->revision)->toBe(0);
    expect(fn () => app(LaunchCampaignService::class)->handle($campaign, $owner))->toThrow(GovernedCampaignLaunchRequired::class);
    foreach ([PolicyEvaluationStage::Launch, PolicyEvaluationStage::Schedule, PolicyEvaluationStage::Lease, PolicyEvaluationStage::PreSend] as $stage) {
        $check = app(GovernedCampaignExecutionGuard::class)->forCampaign($campaign, $stage);
        expect($check->governed)->toBeTrue();
        expect($check->blockers)->not->toBeEmpty();
    }
});

it('rejects foreign ownership and foreign references in service and database', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Draft');
    $foreign = app(SaveObjectiveGoal::class)->handle($other->currentWorkspace(), $other, [
        'name' => 'Foreign', 'measurement' => 'meeting_booked', 'target_count' => 2, 'creation_key' => 'foreign',
    ]);
    $service = app(SaveCampaignComposition::class);
    expect(fn () => $service->handle($campaign, $other, compositionInput()))->toThrow(AuthorizationException::class);
    expect(fn () => $service->handle($campaign, $owner, compositionInput(['objective_goal_version_id' => $foreign->current_goal_version_id])))
        ->toThrow(ValidationException::class);
    expect(fn () => CampaignComposition::query()->create([
        'workspace_id' => $campaign->workspace_id, 'campaign_id' => $campaign->id,
        'revision' => 1, 'objective_goal_version_id' => $foreign->current_goal_version_id,
        'operation_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'created_by_user_id' => $owner->id,
    ]))->toThrow(QueryException::class);
});

it('rejects member mutation and attaching composition after launch', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
    $campaign = app(CreateCampaignService::class)->handle($owner, 'Draft');
    $this->actingAs($member)->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $workspace->id]);
    expect(fn () => app(SaveCampaignComposition::class)->handle($campaign, $member, compositionInput()))
        ->toThrow(AuthorizationException::class);
    $this->actingAs($owner);
    $campaign->forceFill(['launched_at' => now()])->save();
    expect(fn () => app(SaveCampaignComposition::class)->handle($campaign, $owner, compositionInput()))
        ->toThrow(ValidationException::class);
    expect(CampaignComposition::query()->where('revision', '>', 0)->count())->toBe(0);
});
