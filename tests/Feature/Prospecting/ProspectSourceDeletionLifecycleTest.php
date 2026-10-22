<?php

use App\Domain\Prospecting\Exceptions\SourceRunConflict;
use App\Domain\Prospecting\Services\CreateProspectSource;
use App\Domain\Prospecting\Services\DeleteProspectSourceData as DeleteProspectSourceDataService;
use App\Domain\Prospecting\Services\UpdateProspectSource;
use App\Jobs\Prospecting\DeleteProspectSourceData;
use App\Models\Account;
use App\Models\ProspectSource;
use App\Models\SourceObservation;
use App\Models\SourceRun;
use App\Models\User;

function deletionLifecycleSource(User $owner): ProspectSource
{
    return app(CreateProspectSource::class)->handle(
        workspace: $owner->currentWorkspace(),
        kind: 'manual',
        displayName: 'Deletion lifecycle source '.uniqid(),
        actor: $owner,
    );
}

it('retains canonical targets across a false request followed by a true request', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $source = deletionLifecycleSource($owner);
    $account = Account::factory()->create(['workspace_id' => $workspace->id]);
    $run = SourceRun::factory()->forSource($source)->create(['workspace_id' => $workspace->id]);
    $observation = SourceObservation::factory()->forSourceRun($run)->create([
        'workspace_id' => $workspace->id,
        'prospect_source_id' => $source->id,
        'prospect_source_version_id' => $run->prospect_source_version_id,
        'entity_type' => 'account',
        'account_id' => $account->id,
        'person_id' => null,
    ]);
    $delete = app(DeleteProspectSourceDataService::class);

    $delete->prepareForDeletion($source, false);
    expect(DB::table('prospect_source_deletion_targets')
        ->where('prospect_source_id', $source->id)
        ->where('entity_type', 'account')
        ->where('entity_id', $account->id)
        ->exists())->toBeTrue();

    (new DeleteProspectSourceData($workspace->id, $source->id, false))->handle($delete);
    expect($observation->fresh())->toBeNull()
        ->and($account->fresh())->not->toBeNull();

    // The second request upgrades the durable intent after lineage has gone.
    $delete->prepareForDeletion($source->fresh(), true);
    $delete->prepareForDeletion($source->fresh(), false);
    expect($source->fresh()->deletion_delete_canonical)->toBeTrue();
    (new DeleteProspectSourceData($workspace->id, $source->id, false))->handle($delete);

    expect($account->fresh())->toBeNull()
        ->and($source->fresh()->deletion_delete_canonical)->toBeTrue();
});

it('executes a queued deletion against its approved policy after a later policy update', function (): void {
    $owner = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $source = deletionLifecycleSource($owner);
    $approvedVersion = $source->currentVersion;
    $run = SourceRun::factory()->forSource($source)->create(['workspace_id' => $workspace->id]);
    $observation = SourceObservation::factory()->forSourceRun($run)->create([
        'workspace_id' => $workspace->id,
        'prospect_source_id' => $source->id,
        'prospect_source_version_id' => $run->prospect_source_version_id,
    ]);
    $delete = app(DeleteProspectSourceDataService::class);
    $delete->prepareForDeletion($source, false);

    expect(fn () => app(UpdateProspectSource::class)->handle(
        source: $source->fresh(),
        status: 'active',
    ))->toThrow(SourceRunConflict::class);

    app(UpdateProspectSource::class)->handle(
        source: $source->fresh(),
        policy: ['deletion_behavior' => 'retain'],
        actor: $owner,
    );

    $queued = $source->fresh();
    expect($queued->deletion_approved_version_id)->toBe($approvedVersion->id)
        ->and($queued->deletion_approved_policy_hash)->toBe($approvedVersion->policy_hash)
        ->and($queued->currentVersion->policy_snapshot['deletion_behavior'])->toBe('retain');

    (new DeleteProspectSourceData($workspace->id, $source->id, false))->handle($delete);

    expect($observation->fresh())->toBeNull();
});

it('uses one source-scoped queue identity for both deletion parameterizations', function (): void {
    $workspaceId = 42;
    $sourceId = 'source-id';

    $lineage = new DeleteProspectSourceData($workspaceId, $sourceId, false);
    $canonical = new DeleteProspectSourceData($workspaceId, $sourceId, true);

    expect($lineage->uniqueId())->toBe($canonical->uniqueId());
});
