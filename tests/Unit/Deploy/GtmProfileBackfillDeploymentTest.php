<?php

it('prepares the staged release with the GTM backfill before activation', function () {
    $script = file_get_contents(base_path('deploy/pre-activate.sh'));

    expect($script)->toBeString()
        ->and($script)->toContain('artisan migrate --force --no-interaction')
        ->and($script)->toContain('artisan gtm:backfill-profiles --no-interaction')
        ->and(strpos($script, 'artisan gtm:backfill-profiles'))
        ->toBeGreaterThan(strpos($script, 'artisan migrate --force'))
        ->and(strpos($script, 'artisan gtm:backfill-profiles'))
        ->toBeLessThan(strpos($script, 'artisan config:cache'));

    $postActivate = file_get_contents(base_path('deploy/post-activate.sh'));
    expect($postActivate)->toBeString()
        ->and($postActivate)->not->toContain('artisan migrate')
        ->and($postActivate)->not->toContain('gtm:backfill-profiles')
        ->and($postActivate)->toContain('artisan queue:restart');
});
