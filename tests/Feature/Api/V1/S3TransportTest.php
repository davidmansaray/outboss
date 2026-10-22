<?php

use App\Domain\Api\TokenScope;
use App\Domain\Objectives\Services\CreateSegment;
use App\Models\Objective;
use App\Models\Segment;
use App\Models\User;
use App\Models\WorkspaceMembership;

it('lets owners create and read Segments with separate least-privilege scopes', function (): void {
    $owner = User::factory()->create();
    $payload = [
        'name' => 'Revenue leaders',
        'criteria' => ['field' => 'person.job_title', 'operator' => 'contains_normalized', 'value' => 'revenue'],
    ];

    $created = $this->withToken(apiToken($owner, [TokenScope::ObjectivesWrite->value]))
        ->postJson('/api/v1/segments', $payload, ['Idempotency-Key' => 'owner-write'])
        ->assertCreated()
        ->assertJsonPath('data.current_version.name', 'Revenue leaders')
        ->json('data.id');

    $this->app['auth']->forgetGuards();
    $this->withToken(apiToken($owner, [TokenScope::ObjectivesRead->value]))
        ->getJson('/api/v1/segments/'.$created)
        ->assertOk()
        ->assertJsonMissingPath('data.workspace_id');
});

it('rejects Segment requests without the required Objective scope', function (): void {
    $owner = User::factory()->create();

    $this->withToken(apiToken($owner, [TokenScope::PeopleWrite->value]))
        ->postJson('/api/v1/segments', [
            'name' => 'Wrong scope',
            'criteria' => ['field' => 'account.domain_present', 'operator' => 'eq', 'value' => true],
        ], ['Idempotency-Key' => 'wrong-scope'])
        ->assertForbidden()
        ->assertJsonPath('error.type', 'insufficient_scope');
});

it('reserves Segment mutations for workspace owners', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $member = User::factory()->create();
    WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => 'member']);
    $token = $member->createToken('s3-member-write', [TokenScope::ObjectivesWrite->value]);
    $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/segments', [
            'name' => 'Member cannot create',
            'criteria' => ['field' => 'account.domain_present', 'operator' => 'eq', 'value' => true],
        ], ['Idempotency-Key' => 'member-write'])
        ->assertForbidden();
});

it('does not reveal cross-workspace Segment or Snapshot IDs', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $segment = app(CreateSegment::class)->handle(
        $owner->currentWorkspace(),
        $owner,
        'Private segment',
        ['field' => 'account.domain_present', 'operator' => 'eq', 'value' => true],
    );

    $this->withToken(apiToken($intruder, [TokenScope::ObjectivesRead->value]))
        ->getJson('/api/v1/segments/'.$segment->id)
        ->assertNotFound();

    expect(Segment::query()->where('workspace_id', $owner->currentWorkspace()->id)->count())->toBe(1);
});

it('exposes draft Objective shells through least-privilege scopes without leaking tenant or seed data', function (): void {
    $owner = User::factory()->create();
    $created = $this->withToken(apiToken($owner, [TokenScope::ObjectivesWrite->value]))
        ->postJson('/api/v1/objectives', [], ['Idempotency-Key' => 'objective-shell'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonMissingPath('data.workspace_id')
        ->json('data.id');

    $this->app['auth']->forgetGuards();
    $this->withToken(apiToken($owner, [TokenScope::ObjectivesRead->value]))
        ->getJson('/api/v1/objectives/'.$created)
        ->assertOk()
        ->assertJsonPath('data.timeline.0.reason_code', 'created')
        ->assertJsonMissingPath('data.assignment_seed');

    $intruder = User::factory()->create();
    $this->app['auth']->forgetGuards();
    $this->withToken(apiToken($intruder, [TokenScope::ObjectivesRead->value]))
        ->getJson('/api/v1/objectives/'.$created)
        ->assertNotFound();

    expect(Objective::query()->where('workspace_id', $owner->currentWorkspace()->id)->count())->toBe(1);
});
