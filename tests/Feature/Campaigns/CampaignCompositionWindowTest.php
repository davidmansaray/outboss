<?php

use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Campaigns\Services\SaveCampaignComposition;
use App\Domain\Objectives\Services\CreateExecutionAuthorityScope;
use App\Models\CampaignComposition;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('preserves finite campaign windows independently while sharing one allowance', function () {
    $owner = User::factory()->create();
    $scope = app(CreateExecutionAuthorityScope::class)->handle($owner->currentWorkspace(), $owner, [
        'name' => 'Shared', 'operation_id' => (string) Str::uuid(), 'authority' => [
            'starts_at' => now()->toAtomString(), 'ends_at' => now()->addDays(10)->toAtomString(),
            'maximum_people' => 10, 'maximum_accounts' => 10, 'maximum_messages' => 20,
            'maximum_ai_cost_micros' => 0, 'approval_required' => true,
        ],
    ]);
    $save = app(SaveCampaignComposition::class);
    foreach ([2, 4] as $days) {
        $campaign = app(CreateCampaignService::class)->handle($owner, 'Window '.$days);
        $input = ['objective_goal_version_id' => null, 'offer_version_id' => null,
            'execution_authority_scope_id' => $scope->id, 'expected_revision' => 0, 'operation_id' => (string) Str::uuid(),
            'sending_starts_at' => now()->addDay()->toAtomString(), 'sending_ends_at' => now()->addDays($days)->toAtomString()];
        $first = $save->handle($campaign, $owner, $input);
        expect($save->handle($campaign, $owner, $input)->id)->toBe($first->id);
        $revision = $save->handle($campaign, $owner, ['objective_goal_version_id' => null, 'offer_version_id' => null,
            'expected_revision' => 1, 'operation_id' => (string) Str::uuid()]);
        expect($revision->sending_starts_at->equalTo($first->sending_starts_at))->toBeTrue();
        expect($revision->sending_ends_at->equalTo($first->sending_ends_at))->toBeTrue();
        $update = [...$input, 'expected_revision' => 2, 'operation_id' => (string) Str::uuid()];
        foreach ([['sending_ends_at' => now()->toAtomString()],
            ['sending_ends_at' => now()->addDays(11)->toAtomString()], ['sending_starts_at' => now()->subDay()->toAtomString()]] as $invalid) {
            expect(fn () => $save->handle($campaign, $owner, array_replace($update, $invalid)))->toThrow(ValidationException::class);
        }
        expect(CampaignComposition::query()->where('campaign_id', $campaign->id)->max('revision'))->toBe(2);
        $this->actingAs($owner)->post('/campaigns/'.$campaign->id.'/composition', [...$update, 'action' => 'select', 'sending_starts_at' => null, 'sending_ends_at' => null])
            ->assertSessionHasNoErrors();
        expect(CampaignComposition::query()->where('campaign_id', $campaign->id)->orderByDesc('revision')->first()->sending_starts_at)->toBeNull();
    }
    $migration = require database_path('migrations/2026_09_05_101700_add_campaign_composition_window.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'cannot be discarded');
});
