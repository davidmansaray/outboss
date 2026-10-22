<?php

use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Domain\Prospecting\Services\RecoverStaleSourceRun;
use App\Jobs\Prospecting\DeleteProspectSourceData;
use App\Jobs\Prospecting\ExpireSourceObservations;
use App\Jobs\Prospecting\RecoverSourceRun;
use App\Jobs\Prospecting\SweepStalledSourceRuns;
use App\Models\ProspectSource;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function operationsSource(User $user): ProspectSource
{
    return app(CreateProspectSource::class)->handle(
        workspace: $user->currentWorkspace(),
        kind: 'manual',
        displayName: 'Operations source',
        configuration: [],
        policy: [],
        actor: $user,
        origin: 'user',
    );
}

it('expires source observations through a bounded workspace-scoped job', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $source = operationsSource($owner);
    $run = SourceRun::factory()->forSource($source)->create([
        'workspace_id' => $workspace->id,
    ]);
    $observation = SourceObservation::factory()->forSourceRun($run)->create([
        'workspace_id' => $workspace->id,
        'prospect_source_id' => $source->id,
        'prospect_source_version_id' => $run->prospect_source_version_id,
        'retain_until' => now()->subMinute(),
        'observed_values' => ['name' => 'Should be redacted'],
        'provider_record_id' => 'provider-record',
        'canonical_url' => 'https://example.test/private',
    ]);

    (new ExpireSourceObservations($workspace->id, $source->id))->handle(app(App\Domain\Prospecting\Services\ExpireSourceObservations::class));

    $expired = $observation->fresh();
    expect($expired->expired_at)->not->toBeNull()
        ->and($expired->redacted_at)->not->toBeNull()
        ->and($expired->observed_values)->toBeNull()
        ->and($expired->provider_record_id)->toBeNull()
        ->and($expired->canonical_url)->toBeNull()
        ->and($expired->disposition->value)->toBe('expired');
});

it('deletes only the requested workspace source through its queued job', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $source = operationsSource($owner);
    $otherSource = operationsSource($other);
    $run = SourceRun::factory()->forSource($source)->create([
        'workspace_id' => $owner->currentWorkspace()->id,
    ]);
    $otherRun = SourceRun::factory()->forSource($otherSource)->create([
        'workspace_id' => $other->currentWorkspace()->id,
    ]);
    SourceObservation::factory()->forSourceRun($run)->create([
        'workspace_id' => $owner->currentWorkspace()->id,
        'prospect_source_id' => $source->id,
        'prospect_source_version_id' => $run->prospect_source_version_id,
    ]);
    SourceObservation::factory()->forSourceRun($otherRun)->create([
        'workspace_id' => $other->currentWorkspace()->id,
        'prospect_source_id' => $otherSource->id,
        'prospect_source_version_id' => $otherRun->prospect_source_version_id,
    ]);

    (new DeleteProspectSourceData($owner->currentWorkspace()->id, $source->id))
        ->handle(app(App\Domain\Prospecting\Services\DeleteProspectSourceData::class));

    expect(SourceObservation::query()->where('prospect_source_id', $source->id)->exists())->toBeFalse()
        ->and(SourceObservation::query()->where('prospect_source_id', $otherSource->id)->exists())->toBeTrue()
        ->and($source->fresh()->status->value)->toBe('archived')
        ->and($otherSource->fresh()->status->value)->toBe('active');
});

it('sweeps expired leases into workspace-scoped recovery jobs', function (): void {
    Queue::fake();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $source = operationsSource($owner);
    $otherSource = operationsSource($other);
    $stale = SourceRun::factory()->forSource($source)->create([
        'workspace_id' => $owner->currentWorkspace()->id,
        'state' => 'running',
        'lease_owner' => 'worker-a',
        'lease_expires_at' => now()->subMinute(),
    ]);
    $fresh = SourceRun::factory()->forSource($otherSource)->create([
        'workspace_id' => $other->currentWorkspace()->id,
        'state' => 'running',
        'lease_owner' => 'worker-b',
        'lease_expires_at' => now()->addMinute(),
    ]);

    (new SweepStalledSourceRuns($owner->currentWorkspace()->id))->handle();

    Queue::assertPushed(RecoverSourceRun::class, fn (RecoverSourceRun $job): bool => $job->workspaceId === $owner->currentWorkspace()->id && $job->runId === $stale->id
    );
    Queue::assertNotPushed(RecoverSourceRun::class, fn (RecoverSourceRun $job): bool => $job->runId === $fresh->id);
});

it('recovers an expired run lease idempotently and preserves the checkpoint', function (): void {
    $owner = User::factory()->create();
    $source = operationsSource($owner);
    $run = SourceRun::factory()->forSource($source)->create([
        'workspace_id' => $owner->currentWorkspace()->id,
        'state' => 'running',
        'lease_owner' => 'worker-a',
        'lease_expires_at' => now()->subMinute(),
        'checkpoint' => ['row_offset' => 42],
    ]);

    $recover = app(RecoverStaleSourceRun::class);
    expect($recover->handle($owner->currentWorkspace(), $run->id))->toBeTrue()
        ->and($recover->handle($owner->currentWorkspace(), $run->id))->toBeFalse();

    $recovered = $run->fresh();
    expect($recovered->state->value)->toBe('queued')
        ->and($recovered->lease_owner)->toBeNull()
        ->and($recovered->lease_expires_at)->toBeNull()
        ->and($recovered->checkpoint)->toBe(['row_offset' => 42])
        ->and($recovered->safe_error_code)->toBe('source_run_lease_expired');
});
