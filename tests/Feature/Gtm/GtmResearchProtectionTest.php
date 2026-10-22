<?php

use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Services\AiBudgetGateway;
use App\Domain\Gtm\Enums\GtmProfileResearchState;
use App\Domain\Gtm\Enums\GtmResearchRunState;
use App\Domain\Gtm\Services\MergeGtmProfileResearch;
use App\Domain\Gtm\Services\UpdateGtmProfile;
use App\Domain\Gtm\Services\UpdateGtmProfileWebsite;
use App\Domain\Gtm\Support\CanonicalGtmProfilePayload;
use App\Domain\Workspaces\Services\ProvisionWorkspace;
use App\Models\GtmProfileResearchDispatch;
use App\Models\GtmProfileResearchRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    $this->owner = User::factory()->withoutWorkspace()->create(['name' => 'GTM Owner']);
    $this->workspace = app(ProvisionWorkspace::class)->create($this->owner, 'Protection Workspace');
    $this->profile = $this->workspace->gtmProfile()->firstOrFail();
});

it('retire-cancels every active run when the website is cleared', function (): void {
    $website = app(UpdateGtmProfileWebsite::class)->handle(
        $this->profile,
        $this->owner,
        'https://example.com',
        0,
        'protection-website-seed',
    );
    $firstRun = $website->currentResearchRun()->firstOrFail();

    $states = [
        GtmResearchRunState::Running,
        GtmResearchRunState::AwaitingProvider,
        GtmResearchRunState::AwaitingBudget,
    ];
    $runs = collect([$firstRun]);
    foreach ($states as $state) {
        $runs->push(GtmProfileResearchRun::factory()->create([
            'gtm_profile_id' => $this->profile->id,
            'state' => $state,
            'awaiting_reason' => $state === GtmResearchRunState::AwaitingProvider
                ? 'provider'
                : ($state === GtmResearchRunState::AwaitingBudget ? 'budget' : null),
            'website_url_snapshot' => 'https://example.com',
            'input_hash' => hash('sha256', $state->value),
            'initiated_by_user_id' => $this->owner->id,
        ]));
    }

    $budgetRun = app(AiBudgetGateway::class)->openRun(
        $this->workspace,
        $this->owner,
        AiTask::AccountResearch,
    );
    $budgetBackedRun = $runs->last();
    $budgetBackedRun->forceFill(['budget_run_id' => $budgetRun->id])->save();

    foreach ($runs as $run) {
        if (! $run->dispatch()->exists()) {
            GtmProfileResearchDispatch::query()->create([
                'gtm_profile_id' => $this->profile->id,
                'research_run_id' => $run->id,
                'status' => 'pending',
                'available_at' => now(),
                'published_at' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'publish_attempts' => 0,
                'last_error_code' => null,
            ]);
        }
    }

    $this->profile->forceFill([
        'current_research_run_id' => $firstRun->id,
        'research_state' => GtmProfileResearchState::Queued,
    ])->save();

    $cleared = app(UpdateGtmProfileWebsite::class)->handle(
        $this->profile->fresh(),
        $this->owner,
        null,
        1,
        'protection-website-clear',
    );

    expect($cleared->research_state)->toBe(GtmProfileResearchState::NotStarted)
        ->and($cleared->current_research_run_id)->toBeNull()
        ->and(data_get($cleared->currentVersion->payload, 'company.website_url.value'))->toBeNull()
        ->and($runs->map(fn (GtmProfileResearchRun $run): string => $run->fresh()->state->value)->all())
        ->toBe([
            GtmResearchRunState::Cancelled->value,
            GtmResearchRunState::Cancelled->value,
            GtmResearchRunState::Cancelled->value,
            GtmResearchRunState::Cancelled->value,
        ])
        ->and($runs->map(fn (GtmProfileResearchRun $run): ?string => $run->fresh()->safe_failure_code)->all())
        ->each->toBe('research_superseded')
        ->and(GtmProfileResearchDispatch::query()
            ->whereIn('research_run_id', $runs->pluck('id'))
            ->get()
            ->every(fn (GtmProfileResearchDispatch $dispatch): bool => $dispatch->status === 'retired'
                && $dispatch->published_at !== null
                && $dispatch->last_error_code === 'research_superseded'))
        ->toBeTrue()
        ->and($budgetRun->fresh()->status)->toBe('cancelled')
        ->and($budgetRun->fresh()->held_micros)->toBe(0);
});

it('records a conflict for disjoint research against an owner-locked repeated field', function (): void {
    $updated = app(UpdateGtmProfile::class)->handle(
        $this->profile,
        $this->owner,
        'market',
        ['geographies' => ['North America']],
        0,
        'protection-owner-list',
    );
    $run = GtmProfileResearchRun::factory()->create([
        'gtm_profile_id' => $this->profile->id,
        'state' => GtmResearchRunState::Queued,
        'website_url_snapshot' => 'https://example.com',
        'input_hash' => hash('sha256', 'owner-list-conflict'),
        'initiated_by_user_id' => $this->owner->id,
    ]);
    $updated->forceFill(['current_research_run_id' => $run->id])->save();

    $merged = app(MergeGtmProfileResearch::class)->handle(
        $updated,
        $run,
        ['market' => ['geographies' => ['Europe']]],
    );
    $payload = $merged->currentVersion()->firstOrFail()->payload;

    expect(collect(data_get($payload, 'market.geographies'))->firstWhere('value', 'North America'))
        ->toMatchArray([
            'value' => 'North America',
            'owner_locked' => true,
            'provenance' => 'owner_supplied',
        ])
        ->and($merged->reviewItems()
            ->where('field_path', 'market.geographies')
            ->where('type', 'conflict')
            ->whereJsonContains('proposed_value->value', 'Europe')
            ->exists())
        ->toBeTrue();
});

it('records a conflict without resurrecting an owner-tombstoned repeated item', function (): void {
    $added = app(UpdateGtmProfile::class)->handle(
        $this->profile,
        $this->owner,
        'market',
        ['geographies' => ['Europe']],
        0,
        'protection-tombstone-add',
    );
    $removed = app(UpdateGtmProfile::class)->handle(
        $added,
        $this->owner,
        'market',
        ['geographies' => []],
        1,
        'protection-tombstone-remove',
    );
    $run = GtmProfileResearchRun::factory()->create([
        'gtm_profile_id' => $this->profile->id,
        'state' => GtmResearchRunState::Queued,
        'website_url_snapshot' => 'https://example.com',
        'input_hash' => hash('sha256', 'tombstone-conflict'),
        'initiated_by_user_id' => $this->owner->id,
    ]);
    $removed->forceFill(['current_research_run_id' => $run->id])->save();

    $merged = app(MergeGtmProfileResearch::class)->handle(
        $removed,
        $run,
        ['market' => ['geographies' => ['Europe']]],
    );
    $payload = $merged->currentVersion()->firstOrFail()->payload;

    expect(collect(data_get($payload, 'market.geographies'))->firstWhere('value', 'Europe')['tombstoned'])
        ->toBeTrue()
        ->and(CanonicalGtmProfilePayload::visible($payload)['market']['geographies'])
        ->toBeEmpty()
        ->and($merged->reviewItems()
            ->where('field_path', 'market.geographies')
            ->where('type', 'conflict')
            ->whereJsonContains('proposed_value->value', 'Europe')
            ->exists())
        ->toBeTrue();
});

it('derives distinct research item ids for identical values in different fields', function (): void {
    $run = GtmProfileResearchRun::factory()->create([
        'gtm_profile_id' => $this->profile->id,
        'state' => GtmResearchRunState::Queued,
        'website_url_snapshot' => 'https://example.com',
        'input_hash' => hash('sha256', 'field-scoped-stable-ids'),
        'initiated_by_user_id' => $this->owner->id,
    ]);
    $this->profile->forceFill(['current_research_run_id' => $run->id])->save();

    $merged = app(MergeGtmProfileResearch::class)->handle(
        $this->profile,
        $run,
        ['buyers' => [
            'target_roles' => ['Founder'],
            'buying_triggers' => ['Founder'],
        ]],
    );
    $payload = $merged->currentVersion()->firstOrFail()->payload;

    expect(data_get($payload, 'buyers.target_roles.0.id'))
        ->not->toBe(data_get($payload, 'buyers.buying_triggers.0.id'));
});
