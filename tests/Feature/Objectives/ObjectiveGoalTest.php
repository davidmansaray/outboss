<?php

use App\Domain\Objectives\Services\ObjectiveReadiness;
use App\Domain\Objectives\Services\SaveObjectiveGoal;
use App\Models\ConversionDefinition;
use App\Models\Objective;
use App\Models\ObjectiveGoalVersion;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

function independentGoalInput(array $overrides = []): array
{
    return [...[
        'name' => 'Book customer meetings',
        'measurement' => 'meeting_booked',
        'target_count' => 10,
        'creation_key' => 'first-goal',
    ], ...$overrides];
}

it('creates an evergreen Objective without manufacturing a target or deadline', function () {
    $owner = User::factory()->create();
    $input = ['name' => 'Book meetings', 'measurement' => 'meeting_booked', 'creation_key' => 'evergreen'];
    $this->actingAs($owner)->post('/objectives', $input)->assertRedirect();
    $goal = Objective::query()->sole();
    expect($goal->currentGoalVersion->target_count)->toBeNull()
        ->and($goal->currentGoalVersion->deadline)->toBeNull()
        ->and($goal->currentGoalVersion->execution_authority)->toBeNull();
    $this->post('/objectives', $input)->assertRedirect();
    expect(Objective::query()->count())->toBe(1);
    $this->get('/objectives')->assertOk()->assertInertia(fn ($page) => $page->where('objectives.0.target', null));
    $migration = require database_path('migrations/2026_09_05_101400_allow_evergreen_objective_goals.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'cannot invent target counts');
});

it('preserves historical target and deadline values when editing only reusable intent', function () {
    $owner = User::factory()->create();
    $service = app(SaveObjectiveGoal::class);
    $goal = $service->handle($owner->currentWorkspace(), $owner,
        independentGoalInput(['deadline' => now()->addDays(20)->toDateString()]));
    $original = $goal->currentGoalVersion;
    $updated = $service->handle($owner->currentWorkspace(), $owner, [
        'name' => 'Evergreen meeting outcome', 'measurement' => 'meeting_booked', 'lock_version' => $goal->lock_version,
    ], $goal);
    expect($updated->currentGoalVersion->target_count)->toBe($original->target_count)
        ->and($updated->currentGoalVersion->deadline->toDateString())->toBe($original->deadline->toDateString());
});

it('initializes meeting measurement from an Objective without an Offer and refuses replacement', function () {
    $owner = User::factory()->create();
    $goal = app(SaveObjectiveGoal::class)->handle($owner->currentWorkspace(), $owner, independentGoalInput());
    $url = '/objectives/'.$goal->id.'/measurement';
    $other = User::factory()->create();
    $this->actingAs($other)->post($url)->assertNotFound();
    $owner->currentWorkspace()->memberships()->create(['user_id' => $other->id, 'role' => 'member']);
    $this->withSession([User::ACTIVE_WORKSPACE_SESSION_KEY => $goal->workspace_id])->post($url)->assertForbidden();
    $this->actingAs($owner)->post($url)->assertRedirect('/objectives/'.$goal->id);
    expect(ConversionDefinition::query()->where('workspace_id', $goal->workspace_id)->sole()->status->value)->toBe('active');
    expect(Offer::query()->count())->toBe(0);
    $this->post($url)->assertSessionHasErrors('measurement');
    expect(ConversionDefinition::query()->where('workspace_id', $goal->workspace_id)->count())->toBe(1);
    expect($goal->fresh()->current_version_id)->toBeNull();
});

it('saves meaningful independent intent without giving it execution authority', function () {
    $owner = User::factory()->create();
    $goal = app(SaveObjectiveGoal::class)->handle($owner->currentWorkspace(), $owner, independentGoalInput());
    expect($goal->current_version_id)->toBeNull();
    expect($goal->audienceSnapshots()->count())->toBe(0);
    expect(ObjectiveGoalVersion::query()->sole()->target_count)->toBe(10);
    expect(app(ObjectiveReadiness::class)->handle($goal)->blockers)->not->toBeEmpty();
});

it('replays a creation once and rejects conflicting replay content', function () {
    $owner = User::factory()->create();
    $service = app(SaveObjectiveGoal::class);
    $first = $service->handle($owner->currentWorkspace(), $owner, independentGoalInput());
    $second = $service->handle($owner->currentWorkspace(), $owner, independentGoalInput());
    expect($first->id)->toBe($second->id);
    expect(Objective::query()->count())->toBe(1);
    expect(fn () => $service->handle($owner->currentWorkspace(), $owner, independentGoalInput(['target_count' => 20])))
        ->toThrow(ValidationException::class);
});

it('versions intent immutably and rejects stale edits', function () {
    $owner = User::factory()->create();
    $service = app(SaveObjectiveGoal::class);
    $goal = $service->handle($owner->currentWorkspace(), $owner, independentGoalInput());
    $input = independentGoalInput(['name' => 'Revised goal', 'lock_version' => $goal->lock_version]);
    $service->handle($owner->currentWorkspace(), $owner, $input, $goal);
    expect(ObjectiveGoalVersion::query()->orderBy('version')->pluck('name')->all())
        ->toBe(['Book customer meetings', 'Revised goal']);
    expect(fn () => $service->handle($owner->currentWorkspace(), $owner, $input, $goal))
        ->toThrow(ValidationException::class);
    expect(fn () => ObjectiveGoalVersion::query()->first()->update(['name' => 'Rewrite']))
        ->toThrow(LogicException::class);
});

it('denies foreign workspace creation and foreign Objective edits', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $service = app(SaveObjectiveGoal::class);
    expect(fn () => $service->handle($owner->currentWorkspace(), $other, independentGoalInput()))
        ->toThrow(AuthorizationException::class);
    $goal = $service->handle($owner->currentWorkspace(), $owner, independentGoalInput());
    expect(fn () => $service->handle($other->currentWorkspace(), $other,
        independentGoalInput(['lock_version' => $goal->lock_version]), $goal))
        ->toThrow(ModelNotFoundException::class);
});

it('rejects misleading metrics and invalid counts', function () {
    $owner = User::factory()->create();
    foreach ([['measurement' => 'qualified_meeting'], ['target_count' => 0], ['name' => '   ']] as $invalid) {
        expect(fn () => app(SaveObjectiveGoal::class)->handle($owner->currentWorkspace(), $owner, independentGoalInput($invalid)))
            ->toThrow(ValidationException::class);
    }
    expect(Objective::query()->count())->toBe(0);
});

it('enforces current goal ownership at the database boundary', function () {
    $owner = User::factory()->create();
    $service = app(SaveObjectiveGoal::class);
    $first = $service->handle($owner->currentWorkspace(), $owner, independentGoalInput());
    $second = $service->handle($owner->currentWorkspace(), $owner, independentGoalInput(['creation_key' => 'second-goal']));
    expect(fn () => $first->forceFill(['current_goal_version_id' => $second->current_goal_version_id])->save())
        ->toThrow(QueryException::class);
});

it('creates and displays an independent Objective through the web flow', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)->get('/objectives/create')->assertOk();
    $this->post('/objectives', independentGoalInput())->assertRedirect();
    $goal = Objective::query()->sole();
    $this->get('/objectives/'.$goal->id)->assertOk()->assertInertia(fn ($page) => $page
        ->component('objectives/Show')->where('objective.name', 'Book customer meetings')->where('can_edit', true));
    $this->get('/objectives')->assertOk()->assertInertia(fn ($page) => $page
        ->where('objectives.0.name', 'Book customer meetings')->where('objectives.0.target.count', 10));
    $this->patch('/objectives/'.$goal->id, independentGoalInput(['name' => 'Updated target', 'lock_version' => $goal->lock_version]))
        ->assertRedirect('/objectives/'.$goal->id);
    $other = User::factory()->create();
    $this->actingAs($other)->get('/objectives/'.$goal->id)->assertNotFound();
});
