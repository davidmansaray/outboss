<?php

use App\Domain\Mail\Support\ConfidenceInterval;

it('keeps Wilson confidence intervals within the probability bounds', function () {
    $interval = ConfidenceInterval::proportion(1, 2);

    expect($interval['estimate'])->toBe(0.5)
        ->and($interval['lower'])->toBeGreaterThanOrEqual(0.0)
        ->and($interval['upper'])->toBeLessThanOrEqual(1.0)
        ->and($interval['lower'])->toBeLessThan($interval['estimate'])
        ->and($interval['upper'])->toBeGreaterThan($interval['estimate']);
});

it('returns an explicit low-information interval for an empty arm', function () {
    $difference = ConfidenceInterval::difference(0, 0, 1, 10);

    expect($difference['estimate'])->toBeNull()
        ->and($difference['lower'])->toBeNull()
        ->and($difference['upper'])->toBeNull()
        ->and($difference['a']['trials'])->toBe(0)
        ->and($difference['b']['trials'])->toBe(10);
});

it('uses a continuity correction for finite odds ratios', function () {
    $effect = ConfidenceInterval::effectSize(0, 10, 1, 10);

    expect($effect['absolute_difference'])->toBe(-0.1)
        ->and($effect['relative_difference'])->toBe(-1.0)
        ->and($effect['odds_ratio'])->toBeGreaterThan(0.0);
});
