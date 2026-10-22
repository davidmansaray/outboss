<?php

use App\Domain\Objectives\Services\CreateObjective;
use App\Jobs\Objectives\PublishObjectiveRunDispatches;
use App\Models\ObjectiveRun;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/ObjectiveScenarioFixtures.php';

it('lists only the active workspace Objectives with safe readiness and owner actions', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $first = app(CreateObjective::class)->handle($workspace, $owner);
    $second = app(CreateObjective::class)->handle($workspace, $owner);
    $foreignOwner = User::factory()->create();
    app(CreateObjective::class)->handle($foreignOwner->currentWorkspace(), $foreignOwner);

    $this->actingAs($owner)
        ->get(route('objectives.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('objectives/Index')
            ->where('is_owner', true)
            ->has('objectives', 2)
            ->where('objectives.0.id', (string) $second->id)
            ->where('objectives.1.id', (string) $first->id)
            ->where('objectives.0.name', 'Unconfigured Objective')
            ->where('objectives.0.readiness.ready', false)
            ->where('objectives.0.can_start_run', false)
            ->where('objectives.0.execution_underway', false)
            ->where('objectives.0.plan', null)
            ->where('objectives.0.attention_blockers.0.code', 'objective_version_missing')
            ->where('objectives.0.start_blockers.0.code', 'objective_version_missing'));
});

it('lets workspace members inspect the Objective hub without mutation authority', function (): void {
    $owner = User::factory()->create();
    $objective = app(CreateObjective::class)->handle($owner->currentWorkspace(), $owner);
    $member = User::factory()->withoutWorkspace()->create();
    WorkspaceMembership::query()->create([
        'workspace_id' => $owner->currentWorkspace()->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);

    $this->actingAs($member)
        ->get(route('objectives.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('objectives/Index')
            ->where('is_owner', false)
            ->has('objectives', 1)
            ->where('objectives.0.id', (string) $objective->id)
            ->where('objectives.0.can_start_run', false));
});

it('starts one exact Objective run and safely replays the same browser request', function (): void {
    Queue::fake();
    $fixture = s3IncrementThreeReadyFixture();
    $owner = $fixture['owner'];
    $objective = $fixture['objective'];
    $experiment = $fixture['experiment'];

    $this->actingAs($owner)
        ->get(route('objectives.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('objectives.0.id', (string) $objective->id)
            ->where('objectives.0.name', 'Book qualified workshops')
            ->where('objectives.0.experiment.id', (string) $experiment->id)
            ->where('objectives.0.execution_underway', false)
            ->where('objectives.0.attention_blockers', [])
            ->where('objectives.0.can_start_run', true));

    $headers = ['Idempotency-Key' => 'objective-hub-start'];
    $payload = [
        'gtm_experiment_id' => (string) $experiment->id,
        'idempotency_key' => 'objective-hub-start',
    ];
    $location = route('objectives.index', ['focus' => $objective->id]);

    $this->post(route('objectives.runs.store', $objective), $payload, $headers)
        ->assertRedirect($location)
        ->assertSessionHas('success');
    $this->post(route('objectives.runs.store', $objective), $payload, $headers)
        ->assertRedirect($location);

    $this->get(route('objectives.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('objectives.0.execution_underway', true)
            ->where('objectives.0.attention_blockers', [])
            ->where('objectives.0.can_start_run', false)
            ->where('objectives.0.start_blockers.0.code', 'run_already_active'));

    expect(ObjectiveRun::query()->where('objective_id', $objective->id)->count())->toBe(1);
    Queue::assertPushed(PublishObjectiveRunDispatches::class, 1);
});

it('keeps Objective plan generation owner-only and workspace-scoped', function (): void {
    $owner = User::factory()->create();
    $objective = app(CreateObjective::class)->handle($owner->currentWorkspace(), $owner);
    $member = User::factory()->withoutWorkspace()->create();
    WorkspaceMembership::query()->create([
        'workspace_id' => $owner->currentWorkspace()->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);
    $payload = [
        'gtm_experiment_id' => (string) str()->uuid(),
        'idempotency_key' => 'objective-hub-run',
    ];

    $this->actingAs($member)
        ->post(route('objectives.runs.store', $objective), $payload, ['Idempotency-Key' => 'objective-hub-run'])
        ->assertForbidden();

    $foreignOwner = User::factory()->create();
    $this->actingAs($foreignOwner)
        ->post(route('objectives.runs.store', $objective), $payload, ['Idempotency-Key' => 'foreign-objective-hub-run'])
        ->assertNotFound();
});

it('requires authentication for the Objective hub', function (): void {
    $this->get(route('objectives.index'))->assertRedirect(route('login'));
});
