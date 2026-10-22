<?php

namespace App\Domain\Gtm\Support;

use InvalidArgumentException;

final class PublicWebsiteUrl
{
    /** @var array<string, array{expires_at: float, addresses: list<string>}> */
    private static array $resolutionCache = [];

    /**
     * Cheap, deterministic normalization. It rejects URL forms that can never
     * be public, but deliberately performs no DNS work so canonical payload
     * validation cannot trigger repeated network lookups.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $maximum = (int) config('gtm.limits.url_max_length', 2048);
        if (strlen($value) > $maximum) {
            throw new InvalidArgumentException('The website URL is too long.');
        }

        if (preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('The website URL contains unsafe characters.');
        }

        $candidate = str_contains($value, '://') ? $value : 'https://'.$value;
        $parts = parse_url($candidate);
        if (! is_array($parts)) {
            throw new InvalidArgumentException('Enter a valid public website URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw new InvalidArgumentException('The website URL must use HTTPS.');
        }

        if (($parts['user'] ?? null) !== null || ($parts['pass'] ?? null) !== null) {
            throw new InvalidArgumentException('Website credentials are not allowed.');
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            throw new InvalidArgumentException('Enter a valid public website URL.');
        }

        $host = trim($host, '[]');
        $asciiHost = self::asciiHost($host);
        self::assertPublicHostname($asciiHost);

        $port = $parts['port'] ?? null;
        if ($port !== null && (int) $port !== 443) {
            throw new InvalidArgumentException('The website URL uses an unsafe port.');
        }

        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $effectivePort = $port !== null && (int) $port !== 443
            ? ':'.(int) $port
            : '';

        $normalized = $scheme.'://'.$asciiHost.$effectivePort.$path.$query;
        if (strlen($normalized) > $maximum) {
            throw new InvalidArgumentException('The website URL is too long.');
        }

        return $normalized;
    }

    /** Normalize and require a currently public DNS destination. */
    public static function normalizeAndValidate(?string $value, bool $fresh = false): ?string
    {
        $normalized = self::normalize($value);
        if ($normalized !== null) {
            self::assertPublicNetwork($normalized, $fresh);
        }

        return $normalized;
    }

    /**
     * Validate one normalized URL against every A/AAAA answer. A mixed public
     * and private answer is rejected, which closes the common DNS-rebinding
     * bypass where only the first address is inspected.
     */
    public static function assertPublicNetwork(string $value, bool $fresh = false): void
    {
        self::publicAddresses($value, $fresh);
    }

    /**
     * Normalize a URL and return every validated public destination address.
     * Callers that open a socket must pin the request to one of these answers
     * so DNS cannot change between validation and connection.
     *
     * @return list<string>
     */
    public static function publicAddresses(string $value, bool $fresh = false): array
    {
        $normalized = self::normalize($value);
        $host = is_string($normalized) ? parse_url($normalized, PHP_URL_HOST) : null;
        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException('Enter a valid public website URL.');
        }

        return self::resolvePublicHost($host, $fresh);
    }

    /**
     * Resolve each unique host once and cap adversarial provider output before
     * it can fan out into unbounded DNS work.
     *
     * @param  list<string>  $values
     */
    public static function assertPublicNetworkBatch(array $values, bool $fresh = false): void
    {
        $hosts = [];
        foreach ($values as $value) {
            $normalized = self::normalize($value);
            $host = is_string($normalized) ? parse_url($normalized, PHP_URL_HOST) : null;
            if (! is_string($host) || $host === '') {
                throw new InvalidArgumentException('Enter a valid public website URL.');
            }
            $hosts[$host] = true;
        }

        $maximum = max(1, (int) config('gtm.network.max_dns_hosts_per_batch', 25));
        if (count($hosts) > $maximum) {
            throw new InvalidArgumentException('The website list requires too many DNS lookups.');
        }
        foreach (array_keys($hosts) as $host) {
            self::resolvePublicHost($host, $fresh);
        }
    }

    public static function isSafe(?string $value): bool
    {
        try {
            self::normalizeAndValidate($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function asciiHost(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('IP-literal website targets are not allowed.');
        }

        if (! function_exists('idn_to_ascii')) {
            $ascii = $host;
        } else {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        }

        if (! is_string($ascii) || $ascii === '' || strlen($ascii) > 253) {
            throw new InvalidArgumentException('The website hostname is invalid.');
        }

        return strtolower(rtrim($ascii, '.'));
    }

    private static function assertPublicHostname(string $host): void
    {
        if ($host === '' || $host === 'localhost' || ! str_contains($host, '.')) {
            throw new InvalidArgumentException('The website hostname must be public.');
        }

        $reservedSuffixes = [
            '.localhost',
            '.local',
            '.internal',
            '.lan',
            '.home',
            '.test',
            '.invalid',
            '.example',
            '.onion',
        ];
        foreach ($reservedSuffixes as $suffix) {
            if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                throw new InvalidArgumentException('The website hostname is reserved.');
            }
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('The website hostname is invalid.');
        }

    }

    /** @return list<string> */
    private static function resolvePublicHost(string $host, bool $fresh): array
    {
        $now = microtime(true);
        $cached = self::$resolutionCache[$host] ?? null;
        if (! $fresh && is_array($cached) && $cached['expires_at'] > $now) {
            return $cached['addresses'];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            throw new InvalidArgumentException('The website hostname could not be resolved.');
        }

        $maximumAnswers = max(1, (int) config('gtm.network.max_dns_answers_per_host', 16));
        if (count($records) > $maximumAnswers) {
            throw new InvalidArgumentException('The website hostname returned too many network addresses.');
        }

        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($ip)) {
                continue;
            }
            if (filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false) {
                throw new InvalidArgumentException('The website hostname resolves to a private or reserved network.');
            }
            $addresses[] = $ip;
        }
        if ($addresses === []) {
            throw new InvalidArgumentException('The website hostname could not be resolved.');
        }

        $ttl = max(0, (int) config('gtm.network.dns_cache_seconds', 30));
        self::$resolutionCache[$host] = [
            'expires_at' => $now + $ttl,
            'addresses' => array_values(array_unique($addresses)),
        ];
        $maximumEntries = max(1, (int) config('gtm.network.max_dns_cache_entries', 256));
        while (count(self::$resolutionCache) > $maximumEntries) {
            array_shift(self::$resolutionCache);
        }

        return self::$resolutionCache[$host]['addresses'];
    }
}
