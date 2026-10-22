<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;

// Shared by seeding, the test web server and the deterministic outcome driver.
// A fixed business-hour clock keeps approval expiry and mail windows repeatable.
if (getenv('APP_ENV') !== 'testing' || basename((string) getenv('DB_DATABASE')) !== 's3-browser.sqlite') {
    throw new RuntimeException('The S3 browser clock is restricted to the disposable testing database.');
}

$clockFile = dirname((string) getenv('DB_DATABASE')).'/clock.txt';
$fixtureTime = is_file($clockFile) ? trim((string) file_get_contents($clockFile)) : '2026-09-07T10:00:00+00:00';
if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $fixtureTime)) {
    throw new RuntimeException('The disposable browser clock must contain one UTC ISO timestamp.');
}
Carbon::setTestNow($fixtureTime);
CarbonImmutable::setTestNow($fixtureTime);
