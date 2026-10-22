<?php

/**
 * Tiny opt-in router for the managed-mail browser journey.
 *
 * The application-level fakes are intentionally process-local. PHP's
 * built-in server boots a fresh Laravel application for each request, so the
 * fake remote domain/account store would otherwise disappear between the
 * create, verify, and mailbox requests. This router restores and saves only
 * that fake store around each request. It is a test fixture, not an
 * application route, and it never makes a network request to a mail service.
 */

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Mail\Providers\FakeManagedMailboxProvisioner;
use App\Domain\Mail\Services\FakeDnsResolver;
use App\Domain\Mail\Services\FakeDomainAuthChecker;

$stateFile = getenv('MANAGED_MAIL_BROWSER_FAKE_STATE')
    ?: dirname(__DIR__, 2).'/storage/framework/testing/managed-mail-browser-fake.json';
$stateDirectory = dirname($stateFile);

if (! is_dir($stateDirectory)) {
    mkdir($stateDirectory, 0775, true);
}

$state = null;
if (is_file($stateFile)) {
    $decoded = json_decode((string) file_get_contents($stateFile), true);
    if (is_array($decoded)
        && is_array($decoded['domains'] ?? null)
        && is_array($decoded['accounts'] ?? null)) {
        $state = [
            'domains' => $decoded['domains'],
            'accounts' => $decoded['accounts'],
        ];
    }
}

FakeManagedMailboxProvisioner::$store = $state ?? [
    'domains' => [],
    'accounts' => [],
];

$configuredDomains = trim((string) (getenv('MANAGED_MAIL_BROWSER_DOMAINS') ?: ''));
$domains = preg_split(
    '/[\s,]+/',
    $configuredDomains !== ''
        ? $configuredDomains
        : 'browser-outreach.example.com browser-root-a.com browser-root-b.com browser-root-c.com',
    -1,
    PREG_SPLIT_NO_EMPTY,
) ?: [];
$mxHost = trim((string) (getenv('STALWART_INTERNAL_MAIL_HOST') ?: 'mx.fake-managed.test'));

foreach ($domains as $domain) {
    $domain = strtolower(trim($domain));
    if ($domain === '') {
        continue;
    }

    FakeDomainAuthChecker::$results[$domain] = [
        'spf' => 'pass',
        'dmarc' => 'pass',
        'dkim' => 'pass',
    ];
    FakeDnsResolver::set($domain, [$mxHost]);
}

// Let the built-in server serve Vite's already-built public assets directly.
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2).'/public');
$staticPath = realpath($documentRoot.$uri);
$publicRoot = realpath($documentRoot);
if ($uri !== '/' && $staticPath !== false && $publicRoot !== false
    && str_starts_with($staticPath, $publicRoot.DIRECTORY_SEPARATOR)
    && is_file($staticPath)) {
    return false;
}

require dirname(__DIR__, 2).'/public/index.php';

// Persist only fake remote principals. Credentials are test-only values and
// this file belongs under storage/framework/testing, outside source control.
$temporary = $stateFile.'.tmp';
file_put_contents($temporary, json_encode(FakeManagedMailboxProvisioner::$store, JSON_THROW_ON_ERROR), LOCK_EX);
rename($temporary, $stateFile);
