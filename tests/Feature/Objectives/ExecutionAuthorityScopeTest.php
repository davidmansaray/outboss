<?php

use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Campaigns\Services\SaveCampaignComposition;
use App\Domain\Objectives\Services\CreateExecutionAuthorityScope;
use App\Models\CampaignComposition;
use App\Models\ExecutionAuthorityScope;
use App\Models\Objective;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function executionScopeInput(array $authority = []): array
{
    return [
        'name' => 'September outreach allowance', 'operation_id' => (string) Str::uuid(),
        'authority' => array_replace([
            'starts_at' => now()->toAtomString(), 'ends_at' => now()->addDays(10)->toAtomString(),
            'maximum_people' => 10, 'maximum_accounts' => 10, 'maximum_messages' => 20,
            'maximum_ai_cost_micros' => 0, 'approval_required' => true,
        ], $authority),
    ];
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-07 10:00:00 UTC'));
});

it('creates immutable independent authority and replays without inventing fresh allowance after expiry', function () {
    $owner = User::factory()->create();
    $service = app(CreateExecutionAuthorityScope::class);
    $input = executionScopeInput();
    $scope = $service->handle($owner->currentWorkspace(), $owner, $input);
    expect(Objective::query()->count())->toBe(0);
    expect($scope->authority['maximum_messages'])->toBe(20);
    expect(fn () => $scope->update(['name' => 'Changed']))->toThrow(LogicException::class);
    $this->travel(11)->days();
    expect($service->handle($owner->currentWorkspace(), $owner, $input)->id)->toBe($scope->id);
    expect(ExecutionAuthorityScope::query()->count())->toBe(1);
    expect(fn () => $service->handle($owner->currentWorkspace(), $owner, [...$input, 'operation_id' => (string) Str::uuid()]))
        ->toThrow(ValidationException::class, 'future');
    expect(fn () => $service->handle($owner->currentWorkspace(), $owner, [...$input, 'name' => 'Different']))
        ->toThrow(ValidationException::class, 'different execution allowance');
});

it('rejects unbounded or paid authority and foreign actors', function () {
    $owner = User::factory()->create();
    $service = app(CreateExecutionAuthorityScope::class);
    foreach ([['maximum_messages' => 101], ['maximum_people' => 0], ['maximum_ai_cost_micros' => 1],
        ['approval_required' => false], ['ends_at' => now()->addDays(31)->toAtomString()]] as $invalid) {
        expect(fn () => $service->handle($owner->currentWorkspace(), $owner, executionScopeInput($invalid)))
            ->toThrow(ValidationException::class);
    }
    expect(fn () => $service->handle($owner->currentWorkspace(), User::factory()->create(), executionScopeInput()))
        ->toThrow(AuthorizationException::class);
    expect(ExecutionAuthorityScope::query()->count())->toBe(0);
});

it('pins one shared scope with independent campaign targets and preserves it on ordinary selection edits', function () {
    $owner = User::factory()->create();
    $scope = app(CreateExecutionAuthorityScope::class)->handle($owner->currentWorkspace(), $owner, executionScopeInput());
    $save = app(SaveCampaignComposition::class);
    foreach ([3, 7] as $target) {
        $campaign = app(CreateCampaignService::class)->handle($owner, 'Campaign '.$target);
        $input = [
            'objective_goal_version_id' => null, 'offer_version_id' => null,
            'execution_authority_scope_id' => $scope->id, 'target_contribution_count' => $target,
            'expected_revision' => 0, 'operation_id' => (string) Str::uuid(),
        ];
        $first = $save->handle($campaign, $owner, $input);
        expect($save->handle($campaign, $owner, $input)->id)->toBe($first->id);
        $second = $save->handle($campaign, $owner, [
            'objective_goal_version_id' => null, 'offer_version_id' => null,
            'expected_revision' => 1, 'operation_id' => (string) Str::uuid(),
        ]);
        expect($second->execution_authority_scope_id)->toBe($scope->id)
            ->and($second->target_contribution_count)->toBe($target);
    }
    expect(ExecutionAuthorityScope::query()->count())->toBe(1);
    $other = User::factory()->create();
    $foreignCampaign = app(CreateCampaignService::class)->handle($other, 'Foreign campaign');
    expect(fn () => $save->handle($foreignCampaign, $other, [
        'objective_goal_version_id' => null, 'offer_version_id' => null, 'execution_authority_scope_id' => $scope->id,
        'expected_revision' => 0, 'operation_id' => (string) Str::uuid(),
    ]))->toThrow(ValidationException::class, 'this workspace');
    expect(fn () => CampaignComposition::query()->create([
        'workspace_id' => $other->currentWorkspace()->id, 'campaign_id' => $foreignCampaign->id,
        'revision' => 1, 'execution_authority_scope_id' => $scope->id,
        'operation_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'created_by_user_id' => $other->id,
    ]))->toThrow(QueryException::class);
    $migration = require database_path('migrations/2026_09_05_101500_create_execution_authority_scopes.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'cannot be discarded');
});
