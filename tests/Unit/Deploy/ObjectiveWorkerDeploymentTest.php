<?php

use App\Jobs\Objectives\ExecuteObjectiveRunPhase;

it('keeps Objective worker reservation and shutdown windows beyond its timeout', function (): void {
    $job = new ExecuteObjectiveRunPhase(1, 'run', 'phase', 'dispatch');
    expect(config('queue.connections.redis-objectives.retry_after'))->toBeGreaterThan($job->timeout)
        ->and(config('queue.connections.redis-objectives.connection'))->toBe(config('queue.connections.redis.connection'));

    $supervisor = file_get_contents(base_path('deploy/supervisor-outboss.conf'));
    expect($supervisor)->toContain('[program:outboss-objectives]')
        ->and($supervisor)->toContain('queue:work redis-objectives --queue=objectives')
        ->and($supervisor)->toContain('stopwaitsecs=660');
    expect(file_get_contents(base_path('deploy/post-activate.sh')))->toContain('outboss-objectives:*');
});
