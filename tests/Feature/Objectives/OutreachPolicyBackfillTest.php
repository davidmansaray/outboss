<?php

use App\Models\OutreachPolicy;
use App\Models\Workspace;

it('backfills default outreach policies idempotently and supports dry runs and cursors', function (): void {
    $first = Workspace::query()->create(['name' => 'Legacy one', 'type' => 'team']);
    $second = Workspace::query()->create(['name' => 'Legacy two', 'type' => 'team']);

    $this->artisan('objectives:backfill-default-policies', ['--dry-run' => true])
        ->assertSuccessful();
    expect(OutreachPolicy::query()->count())->toBe(0);

    $this->artisan('objectives:backfill-default-policies', ['--after-id' => $first->id])
        ->assertSuccessful();
    expect(OutreachPolicy::query()->pluck('workspace_id')->all())->toBe([$second->id]);

    $this->artisan('objectives:backfill-default-policies')->assertSuccessful();
    $this->artisan('objectives:backfill-default-policies')->assertSuccessful();

    expect(OutreachPolicy::query()->count())->toBe(2)
        ->and(OutreachPolicy::query()->where('workspace_id', $first->id)->firstOrFail()->current_version_id)->not->toBeNull();
});
