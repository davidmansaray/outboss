<?php

use App\Domain\Objectives\Services\SaveObjectiveGoal;
use App\Models\Campaign;
use App\Models\ConversionDefinition;
use App\Models\User;

it('preserves an authorized campaign return through Objective edits and measurement creation', function () {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $objective = app(SaveObjectiveGoal::class)->handle($workspace, $owner, [
        'name' => 'Meetings', 'measurement' => 'meeting_booked', 'creation_key' => 'return-goal',
    ]);
    $campaign = Campaign::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);
    $url = '/objectives/'.$objective->id.'?from_campaign='.$campaign->id;
    $this->actingAs($owner)->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('return_campaign.id', $campaign->id)->where('return_campaign.name', $campaign->name));
    $this->patch('/objectives/'.$objective->id, [
        'name' => 'Updated meetings', 'measurement' => 'meeting_booked',
        'lock_version' => $objective->lock_version, 'from_campaign' => $campaign->id,
    ])->assertRedirect($url);
    $this->post('/objectives/'.$objective->id.'/measurement', ['from_campaign' => $campaign->id])->assertRedirect($url);
    expect(ConversionDefinition::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('rejects foreign campaign return context before changing an Objective or creating measurement', function () {
    $owner = User::factory()->create();
    $objective = app(SaveObjectiveGoal::class)->handle($owner->currentWorkspace(), $owner, [
        'name' => 'Meetings', 'measurement' => 'meeting_booked', 'creation_key' => 'foreign-return-goal',
    ]);
    $foreign = Campaign::factory()->create();
    $url = '/objectives/'.$objective->id;
    $this->actingAs($owner)->get($url.'?from_campaign='.$foreign->id)->assertNotFound();
    $this->patch($url, ['from_campaign' => $foreign->id])->assertNotFound();
    $this->post($url.'/measurement', ['from_campaign' => $foreign->id])->assertNotFound();
    expect($objective->fresh()->lock_version)->toBe($objective->lock_version)
        ->and(ConversionDefinition::query()->where('workspace_id', $objective->workspace_id)->exists())->toBeFalse();
});
