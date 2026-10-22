<?php

use Symfony\Component\Finder\Finder;

/**
 * The iOS changes feed reads threads.updated_at. Query-builder updates and
 * deletes skip Eloquent timestamps, so a phone would never hear about them.
 * Save threads through the model instead.
 */
it('never bulk-updates or deletes threads outside Eloquent saves', function () {
    $patterns = [
        '/Thread::(?:query\(\)|where\w*\(|whereKey\()[^;]*->(?:update|delete|increment|decrement|upsert)\(/s',
        '/->threads\(\)[^;]*->(?:update|delete|increment|decrement|upsert)\(/s',
        "/DB::table\\(\\s*'threads'\\s*\\)/",
    ];

    $offenders = collect(Finder::create()->files()->in(app_path())->name('*.php'))
        ->filter(fn ($file) => collect($patterns)->contains(fn (string $pattern) => preg_match($pattern, $file->getContents()) === 1))
        ->map(fn ($file) => str_replace(base_path().'/', '', $file->getRealPath()))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
