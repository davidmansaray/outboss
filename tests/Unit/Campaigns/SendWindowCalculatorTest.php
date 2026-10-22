<?php

use App\Domain\Campaigns\Support\SendWindowCalculator;
use Carbon\CarbonImmutable;

it('keeps a weekday window instant and rolls weekends to Monday', function () {
    $calculator = new SendWindowCalculator;

    $fridayAfternoon = CarbonImmutable::parse('2026-08-14 16:30:00', 'America/New_York');
    expect($calculator->nextAllowed($fridayAfternoon, 'America/New_York'))
        ->timezoneName->toBe('UTC')
        ->toDateTimeString()->toBe('2026-08-14 20:30:00');

    $fridayEvening = CarbonImmutable::parse('2026-08-14 17:30:00', 'America/New_York');
    expect($calculator->nextAllowed($fridayEvening, 'America/New_York'))
        ->timezoneName->toBe('UTC')
        ->toDateTimeString()->toBe('2026-08-17 13:00:00');

    $saturday = CarbonImmutable::parse('2026-08-15 11:00:00', 'America/New_York');
    expect($calculator->nextAllowed($saturday, 'America/New_York'))
        ->timezoneName->toBe('UTC')
        ->toDateTimeString()->toBe('2026-08-17 13:00:00');
});

it('handles midnight and DST spring-forward in New York', function () {
    $calculator = new SendWindowCalculator;
    $beforeWindow = CarbonImmutable::parse('2026-03-08 07:30:00', 'America/New_York');

    expect($calculator->nextAllowed($beforeWindow, 'America/New_York'))
        ->timezoneName->toBe('UTC')
        ->toDateTimeString()->toBe('2026-03-09 13:00:00');
});

it('returns the correct UTC instant for the production Halifax schedule', function () {
    $calculator = new SendWindowCalculator;
    $oneWeekLater = CarbonImmutable::parse('2026-09-06 17:28:08', 'America/Halifax');

    expect($calculator->nextAllowed(
        $oneWeekLater,
        'America/Halifax',
        '00:00:00',
        '23:59:00',
        false,
    ))
        ->timezoneName->toBe('UTC')
        ->toDateTimeString()->toBe('2026-09-06 20:28:08');
});
