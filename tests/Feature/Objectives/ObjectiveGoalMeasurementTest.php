<?php

use App\Domain\Conversions\Services\ActivateConversionDefinition;
use App\Domain\Conversions\Services\CopyConversionDefinitionTemplates;
use App\Domain\Objectives\Services\SaveObjectiveGoal;
use App\Domain\Objectives\Support\CanonicalObjectivePayload;
use App\Models\ObjectiveGoalVersion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

function goalMeasurementInput(User $owner): array
{
    $definition = app(CopyConversionDefinitionTemplates::class)->handle($owner->currentWorkspace(), $owner, ['meeting_booked'])->firstOrFail();
    app(ActivateConversionDefinition::class)->handle($definition, $owner, $definition->lock_version);

    return ['name' => 'Meetings', 'measurement' => 'meeting_booked', 'creation_key' => 'measured-goal',
        'conversion_definition_version_id' => $definition->current_version_id];
}

it('saves measurement independently and preserves it during ordinary edits', function () {
    $owner = User::factory()->create();
    $input = goalMeasurementInput($owner);
    $service = app(SaveObjectiveGoal::class);
    $goal = $service->handle($owner->currentWorkspace(), $owner, $input);
    expect($goal->currentGoalVersion->conversion_definition_version_id)->toBe($input['conversion_definition_version_id']);
    expect($goal->currentGoalVersion->execution_authority)->toBeNull();
    expect($goal->currentGoalVersion->target_count)->toBeNull();
    expect($goal->current_version_id)->toBeNull();
    unset($input['conversion_definition_version_id']);
    $goal = $service->handle($owner->currentWorkspace(), $owner, [...$input, 'name' => 'Renamed', 'lock_version' => $goal->lock_version], $goal);
    expect($goal->fresh()->currentGoalVersion->conversion_definition_version_id)->not->toBeNull();
    expect(ObjectiveGoalVersion::query()->count())->toBe(2);
});

it('rejects goal authority writes including clearing historical authority', function (mixed $authority) {
    $owner = User::factory()->create();
    $input = goalMeasurementInput($owner);
    expect(fn () => app(SaveObjectiveGoal::class)->handle($owner->currentWorkspace(), $owner, [...$input, 'execution_authority' => $authority]))
        ->toThrow(ValidationException::class);
    expect(ObjectiveGoalVersion::query()->count())->toBe(0);
})->with([null, [['maximum_messages' => 20]]]);

it('rejects foreign measurement definitions without an authority bundle', function () {
    $owner = User::factory()->create();
    $input = goalMeasurementInput(User::factory()->create());
    expect(fn () => app(SaveObjectiveGoal::class)->handle($owner->currentWorkspace(), $owner, $input))->toThrow(ValidationException::class);
});

it('preserves expired historical authority values on a name edit', function () {
    $owner = User::factory()->create();
    $input = goalMeasurementInput($owner);
    $service = app(SaveObjectiveGoal::class);
    $goal = $service->handle($owner->currentWorkspace(), $owner, $input);
    $legacy = $goal->currentGoalVersion->replicate();
    $authority = ['starts_at' => '2026-01-01T00:00:00Z', 'ends_at' => '2026-01-08T00:00:00Z',
        'maximum_people' => 10, 'maximum_accounts' => 10, 'maximum_messages' => 10, 'maximum_ai_cost_micros' => 0, 'approval_required' => true];
    $legacy->forceFill(['version' => 2, 'creation_key' => null, 'execution_authority' => $authority, 'content_hash' => str_repeat('a', 64)])->save();
    $goal->forceFill(['current_goal_version_id' => $legacy->id])->save();
    unset($input['conversion_definition_version_id']);
    $saved = $service->handle($owner->currentWorkspace(), $owner, [...$input, 'name' => 'Renamed', 'lock_version' => $goal->lock_version], $goal);
    // MySQL normalizes JSON object key order; compare canonical content, not PHP insertion order.
    expect(CanonicalObjectivePayload::hash($saved->fresh()->currentGoalVersion->execution_authority))
        ->toBe(CanonicalObjectivePayload::hash($authority));
    expect($legacy->fresh()->content_hash)->toBe(str_repeat('a', 64));
});
