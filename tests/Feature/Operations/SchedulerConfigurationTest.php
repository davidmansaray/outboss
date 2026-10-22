<?php

use Illuminate\Console\Scheduling\Schedule;

it('prevents every Outboss scheduled task from overlapping', function () {
    $expectedLockMinutes = [
        'outboss-schedule-sends' => 5,
        'outboss-outbox-sweep' => 5,
        'outboss-sync-mailboxes' => 10,
        'outboss-reconcile-unknown' => 10,
        'outboss-reconcile-ai-provider-operations' => 10,
        'outboss-objective-dispatch-sweep' => 5,
        'outboss-objective-run-recovery' => 5,
        'outboss-expire-imports' => 120,
        'outboss-refresh-workspace-avatars' => 60,
    ];

    $events = collect(app(Schedule::class)->events())->keyBy('description');

    foreach ($expectedLockMinutes as $name => $lockMinutes) {
        $event = $events->get($name);

        expect($event)->not->toBeNull()
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBe($lockMinutes);
    }
});

it('rechecks stale workspace favicons daily without doing network work in the scheduler', function () {
    config()->set('workspaces.avatars.refresh_after_days', 7);

    $event = collect(app(Schedule::class)->events())
        ->keyBy('description')
        ->get('outboss-refresh-workspace-avatars');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('17 3 * * *')
        ->and($event->command)->toContain('workspaces:refresh-avatars')
        ->and($event->command)->toContain('--routine');
});
