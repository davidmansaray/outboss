<?php

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;

it('reports bounded execution repair with explicit workspace scope', function (): void {
    $workspace = User::factory()->create()->currentWorkspace();
    $this->artisan('odg-s3:reconcile-outcomes', ['--workspace' => $workspace->id, '--limit' => 2, '--json' => true])
        ->expectsOutput(json_encode(['workspace_id' => $workspace->id, 'limit' => 2, 'repaired' => 0, 'projections' => ['execution' => 0, 'conversations' => 0, 'attribution' => 0]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))
        ->assertSuccessful();
});

it('rejects invalid execution repair bounds before scanning', function (string $limit): void {
    $this->artisan('odg-s3:reconcile-outcomes', ['--limit' => $limit])->assertExitCode(2);
})->with(['0', '-1', '501', 'all', '1.5']);

it('rejects an unknown or invalid repair workspace', function (string $workspace): void {
    $this->artisan('odg-s3:reconcile-outcomes', ['--workspace' => $workspace])->assertExitCode(2);
})->with(['0', '-1', '999999', 'all']);

it('registers a non-overlapping minute-level exposure repair sweep', function (): void {
    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'outboss-objective-outcome-recovery');
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
