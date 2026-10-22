<?php

use App\Domain\Prospecting\Exceptions\SourceRunConflict;
use App\Domain\Prospecting\Services\StartSourceRun;
use App\Jobs\Prospecting\DeleteProspectSourceData;
use App\Models\ProspectSource;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('queues source lineage deletion for an owning workspace member', function () {
    Queue::fake();

    $user = User::factory()->create();
    $workspace = $user->currentWorkspace();
    $source = ProspectSource::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson(route('prospect-sources.data.destroy', $source), [
            'delete_canonical' => true,
        ]);

    $response->assertStatus(202)
        ->assertJson(['data' => ['queued' => true]]);

    expect($source->fresh()->status->value)->toBe('archived')
        ->and(fn () => app(StartSourceRun::class)->handle(
            $workspace,
            $source,
            null,
            'run-after-delete-request',
            actor: $user,
        ))->toThrow(SourceRunConflict::class, 'Archived or paused sources cannot start new runs.');

    Queue::assertPushed(DeleteProspectSourceData::class, function (DeleteProspectSourceData $job) use ($workspace, $source): bool {
        return $job->workspaceId === $workspace->id
            && $job->sourceId === $source->id
            && $job->deleteCanonical;
    });
});

it('does not queue source deletion for a non-owner', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = $owner->currentWorkspace();
    $workspace->users()->attach($member->id, ['role' => 'member']);
    $source = ProspectSource::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $this->actingAs($member)
        ->deleteJson(route('prospect-sources.data.destroy', $source))
        ->assertForbidden();

    Queue::assertNothingPushed();
});
