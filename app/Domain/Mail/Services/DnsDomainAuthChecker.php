<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\DnsRecordResolver;
use App\Domain\Mail\Contracts\DomainAuthChecker;
use App\Domain\Mail\Contracts\DomainAuthEvidenceChecker;
use App\Domain\Mail\Support\DnsZoneFileParser;

/**
 * Resolve SPF, DMARC, and provisioner-specific DKIM records.
 *
 * The one-argument check remains suitable for the general mailbox-auth
 * guidance endpoint. Managed-domain verification calls
 * checkWithExpectedRecords(), which requires a selector returned by the
 * provisioner and compares the observed public key when one was supplied.
 */
final class DnsDomainAuthChecker implements DomainAuthChecker, DomainAuthEvidenceChecker
{
    public function __construct(
        private readonly ?DnsRecordResolver $records = null,
        private readonly ?DnsZoneFileParser $zoneFiles = null,
    ) {}

    /**
     * @return array{spf: 'pass'|'missing'|'unknown', dmarc: 'pass'|'missing'|'unknown', dkim: 'unknown'}
     */
    public function check(string $domain): array
    {
        $host = $this->normaliseHost($domain);

        if ($host === null) {
            return [
                'spf' => 'unknown',
                'dmarc' => 'unknown',
                'dkim' => 'unknown',
            ];
        }

        return [
            'spf' => $this->lookupTxt($host, 'v=spf1'),
            'dmarc' => $this->lookupTxt('_dmarc.'.$host, 'v=dmarc1'),
            // There is no safe selector to guess for a generic mailbox. The
            // managed verifier supplies the exact generated selector below.
            'dkim' => 'unknown',
        ];
    }

    /**
     * @param  array<string, mixed>  $expectedRecords
     * @return array{spf: 'pass'|'missing'|'unknown', dmarc: 'pass'|'missing'|'unknown', dkim: 'pass'|'missing'|'unknown'}
     */
    public function checkWithExpectedRecords(string $domain, array $expectedRecords): array
    {
        $normalisedDomain = $this->normaliseHost($domain);
        if ($normalisedDomain === null) {
            return [
                'spf' => 'unknown',
                'dmarc' => 'unknown',
                'dkim' => 'unknown',
            ];
        }

        return [
            'spf' => $this->lookupExpectedSpf($normalisedDomain, [
                ...$expectedRecords,
                // The managed verifier adds this before invoking the
                // evidence checker. Keeping the field in the expected
                // record set makes the outbound-IP requirement explicit.
                'managed_instance_key' => $expectedRecords['managed_instance_key'] ?? null,
            ]),
            'dmarc' => $this->lookupTxt('_dmarc.'.$normalisedDomain, 'v=dmarc1'),
            'dkim' => $this->lookupExpectedDkim($normalisedDomain, $expectedRecords),
        ];
    }

    /**
     * Keep this helper public for focused unit tests and for callers that
     * already have a DNS response in the PHP dns_get_record() shape.
     *
     * @param  list<array<string, mixed>>  $records
     * @return 'pass'|'missing'|'unknown'
     */
    public function statusForRecords(array $records, string $prefix): string
    {
        $prefix = strtolower(trim($prefix));
        foreach ($records as $record) {
            $txt = $this->recordText($record);
            if ($txt === '') {
                continue;
            }

            if ($prefix === 'v=dmarc1') {
                if ($this->isValidDmarcRecord($txt)) {
                    return 'pass';
                }

                continue;
            }

            $matches = $prefix === 'v=spf1'
                ? preg_match('/\A'.preg_quote($prefix, '/').'(?=\s|;|$)/i', $txt) === 1
                : str_starts_with(strtolower($txt), $prefix);
            if ($matches) {
                return 'pass';
            }
        }

        return 'missing';
    }

    /**
     * A DMARC version marker alone is not an enforceable policy record. The
     * required policy tag must be present exactly once and use a value from
     * RFC 7489. Reject duplicate or malformed tags rather than selecting a
     * convenient value from an ambiguous record.
     */
    private function isValidDmarcRecord(string $value): bool
    {
        $parts = array_values(array_filter(
            array_map('trim', explode(';', trim($value, " \t\n\r\0\x0B\""))),
            static fn (string $part): bool => $part !== '',
        ));
        if ($parts === [] || preg_match('/\Av\s*=\s*dmarc1\z/i', $parts[0]) !== 1) {
            return false;
        }

        $tags = [];
        foreach ($parts as $part) {
            if (preg_match('/\A([a-z][a-z0-9_]*)\s*=\s*(.*?)\z/i', $part, $matches) !== 1) {
                return false;
            }

            $name = strtolower($matches[1]);
            if (array_key_exists($name, $tags)) {
                return false;
            }

            $tags[$name] = strtolower(trim($matches[2]));
        }

        return ($tags['v'] ?? null) === 'dmarc1'
            && in_array($tags['p'] ?? null, ['none', 'quarantine', 'reject'], true);
    }

    /** @return 'pass'|'missing'|'unknown' */
    private function lookupTxt(string $host, string $prefix): string
    {
        $records = ($this->records ?? new DohDnsRecordResolver)->lookup($host, 'TXT');

        return $records === null ? 'unknown' : $this->statusForRecords($records, $prefix);
    }

    /**
     * Require a provisioner-generated SPF expectation (or an explicit
     * outbound IPv4) instead of treating any TXT record beginning with
     * `v=spf1` as authorization. This deliberately rejects `v=spf1 -all`.
     *
     * @param  array<string, mixed>  $expectedRecords
     * @return 'pass'|'missing'|'unknown'
     */
    private function lookupExpectedSpf(string $domain, array $expectedRecords): string
    {
        $expectedValue = $this->expectedSpfValue($domain, $expectedRecords);
        $outboundIp = $this->expectedOutboundIp($expectedRecords);

        if ($expectedValue === null && array_key_exists('spf', $expectedRecords)) {
            // Do not replace a malformed provider SPF value with a synthetic
            // IP expectation merely because instance configuration exists.
            return 'unknown';
        }

        if ($expectedValue === null && $outboundIp === null) {
            return 'unknown';
        }

        $expectedTerms = $expectedValue === null
            ? [['token' => 'ip4:'.$outboundIp, 'name' => 'ip4', 'qualifier' => '+', 'value' => (string) $outboundIp]]
            : $this->spfTerms($expectedValue);
        if ($expectedTerms === []) {
            return 'unknown';
        }

        // An expected record that only says `-all` does not authorize the
        // managed server. It must never become a readiness pass by matching
        // itself exactly.
        if ($this->authorisingSpfTerms($expectedTerms) === []) {
            return 'missing';
        }

        if ($outboundIp !== null && $this->hasIpMechanism($expectedTerms)
            && ! $this->spfAuthorisesIp($expectedTerms, $outboundIp)) {
            return 'missing';
        }

        $observed = ($this->records ?? new DohDnsRecordResolver)->lookup($domain, 'TXT');
        if ($observed === null) {
            return 'unknown';
        }

        foreach ($observed as $record) {
            $value = $this->recordText($record);
            $observedTerms = $this->spfTerms($value);
            if ($observedTerms === [] || $this->authorisingSpfTerms($observedTerms) === []) {
                continue;
            }

            if (! $this->containsExpectedSpfTerms($expectedTerms, $observedTerms)) {
                continue;
            }

            if ($outboundIp !== null && $this->hasIpMechanism($expectedTerms)
                && ! $this->spfAuthorisesIp($observedTerms, $outboundIp)) {
                continue;
            }

            return 'pass';
        }

        return 'missing';
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function expectedSpfValue(string $domain, array $records): ?string
    {
        $spf = $records['spf'] ?? null;
        if (is_string($spf)) {
            return $this->spfTerms($spf) === [] ? null : trim($spf);
        }

        if (is_array($spf)) {
            $host = $spf['host'] ?? $spf['name'] ?? null;
            if (is_string($host)) {
                if (trim($host, " .\t\n\r\0\x0B") === '@') {
                    $normalisedHost = $domain;
                } else {
                    $normalisedHost = $this->normaliseHost($host);
                }
                if ($normalisedHost === null || $normalisedHost !== $domain) {
                    return null;
                }
            }

            foreach (['value', 'txt', 'data'] as $key) {
                $value = $spf[$key] ?? null;
                if (is_string($value) && $this->spfTerms($value) !== []) {
                    return trim($value);
                }
            }
        }

        $zoneFile = $records['zone_file'] ?? null;
        if (! is_string($zoneFile)) {
            return null;
        }

        $zoneFiles = $this->zoneFiles ?? new DnsZoneFileParser;
        foreach ($zoneFiles->records($zoneFile) as $record) {
            if ($record['type'] !== 'TXT') {
                continue;
            }

            $host = strtolower(trim($record['host'], " .\t\n\r\0\x0B"));
            if ($host === '@') {
                $host = $domain;
            } elseif (! str_contains($host, '.')) {
                $host .= '.'.$domain;
            }
            if ($host !== $domain) {
                continue;
            }

            $value = $zoneFiles->txtValue($record['value']);
            if ($value !== null && $this->spfTerms($value) !== []) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function expectedOutboundIp(array $records): ?string
    {
        $values = [
            $records['outbound_ipv4'] ?? null,
            $records['outbound_ip'] ?? null,
            is_array($records['ptr'] ?? null) ? ($records['ptr']['ip'] ?? null) : null,
        ];

        $instanceKey = trim((string) ($records['managed_instance_key'] ?? ''));
        if ($instanceKey !== '') {
            $values[] = config('outboss.managed_mail.instances.'.$instanceKey.'.outbound_ipv4');
        }

        foreach ($values as $value) {
            if (is_string($value)
                && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Parse the small SPF subset needed for readiness evidence. The token
     * itself is retained so expected mechanisms can be compared without
     * accepting an unrelated include or IP range.
     *
     * @return list<array{token: string, name: string, qualifier: string, value: string}>
     */
    private function spfTerms(string $value): array
    {
        $value = trim($value, " \t\n\r\0\x0B\"");
        $parts = preg_split('/\s+/', $value) ?: [];
        if ($parts === [] || preg_match('/\Av=spf1\z/i', (string) $parts[0]) !== 1) {
            return [];
        }

        $terms = [];
        foreach (array_slice($parts, 1) as $part) {
            $part = strtolower(trim($part, " \t\n\r\0\x0B\""));
            if ($part === '') {
                continue;
            }

            $qualifier = '+';
            if (in_array($part[0], ['+', '-', '~', '?'], true)) {
                $qualifier = $part[0];
                $part = substr($part, 1);
            }

            if ($part === '') {
                return [];
            }

            $separator = strpos($part, ':');
            $equals = strpos($part, '=');
            $cut = $separator === false ? $equals : ($equals === false ? $separator : min($separator, $equals));
            $name = $cut === false ? $part : substr($part, 0, $cut);
            $termValue = $cut === false ? '' : substr($part, $cut + 1);
            if (! in_array($name, ['a', 'all', 'exists', 'include', 'ip4', 'ip6', 'mx', 'ptr', 'exp', 'redirect'], true)) {
                continue;
            }
            if (in_array($name, ['exists', 'include', 'ip4', 'ip6', 'exp', 'redirect'], true)
                && $termValue === '') {
                return [];
            }
            if ($name === 'ip4' && ! $this->validSpfIpRange($termValue, 4)) {
                return [];
            }
            if ($name === 'ip6' && ! $this->validSpfIpRange($termValue, 6)) {
                return [];
            }

            $terms[] = [
                'token' => $qualifier.$part,
                'name' => $name,
                'qualifier' => $qualifier,
                'value' => $termValue,
            ];
        }

        return $terms;
    }

    private function validSpfIpRange(string $value, int $family): bool
    {
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);
        $flags = $family === 4 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
        if (filter_var($address, FILTER_VALIDATE_IP, $flags) === false) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        $prefixLength = (int) $prefix;
        $maxBits = $family === 4 ? 32 : 128;

        return (string) $prefixLength === $prefix
            && $prefixLength >= 0
            && $prefixLength <= $maxBits;
    }

    /**
     * @param  list<array{token: string, name: string, qualifier: string, value: string}>  $terms
     * @return list<array{token: string, name: string, qualifier: string, value: string}>
     */
    private function authorisingSpfTerms(array $terms): array
    {
        return array_values(array_filter(
            $terms,
            static fn (array $term): bool => $term['qualifier'] === '+'
                && in_array($term['name'], ['a', 'exists', 'include', 'ip4', 'ip6', 'mx', 'ptr', 'redirect'], true),
        ));
    }

    /**
     * @param  list<array{token: string, name: string, qualifier: string, value: string}>  $expected
     * @param  list<array{token: string, name: string, qualifier: string, value: string}>  $observed
     */
    private function containsExpectedSpfTerms(array $expected, array $observed): bool
    {
        foreach ($this->authorisingSpfTerms($expected) as $expectedTerm) {
            $matched = false;
            foreach ($observed as $observedTerm) {
                if ($observedTerm['token'] === $expectedTerm['token']) {
                    $matched = true;

                    break;
                }
            }
            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{token: string, name: string, qualifier: string, value: string}>  $terms
     */
    private function hasIpMechanism(array $terms): bool
    {
        foreach ($terms as $term) {
            if (in_array($term['name'], ['ip4', 'ip6'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{token: string, name: string, qualifier: string, value: string}>  $terms
     */
    private function spfAuthorisesIp(array $terms, string $ip): bool
    {
        $family = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 'ip4' : 'ip6';
        $needle = inet_pton($ip);
        if ($needle === false) {
            return false;
        }

        foreach ($terms as $term) {
            if ($term['qualifier'] !== '+' || $term['name'] !== $family || $term['value'] === '') {
                continue;
            }

            [$network, $prefix] = array_pad(explode('/', $term['value'], 2), 2, null);
            $networkPacked = inet_pton($network);
            if ($networkPacked === false) {
                continue;
            }
            $prefixLength = $prefix === null
                ? ($family === 'ip4' ? 32 : 128)
                : (int) $prefix;
            $maxBits = $family === 'ip4' ? 32 : 128;
            if ($prefixLength < 0 || $prefixLength > $maxBits) {
                continue;
            }

            $bytes = intdiv($prefixLength, 8);
            $remainingBits = $prefixLength % 8;
            if (substr($needle, 0, $bytes) !== substr($networkPacked, 0, $bytes)) {
                continue;
            }
            if ($remainingBits === 0) {
                return true;
            }

            $needleByte = ord($needle[$bytes]);
            $networkByte = ord($networkPacked[$bytes]);
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            if (($needleByte & $mask) === ($networkByte & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $expectedRecords
     * @return 'pass'|'missing'|'unknown'
     */
    private function lookupExpectedDkim(string $domain, array $expectedRecords): string
    {
        $expectations = array_values(array_filter(
            $this->expectedDkimRecords($domain, $expectedRecords),
            fn (array $expectation): bool => $this->publicKey($expectation['value']) !== null,
        ));
        if ($expectations === []) {
            // A selector and a parseable provider key must both be present.
            // Guessing "default", or accepting a selector with no key, could
            // validate the wrong DKIM identity.
            return 'unknown';
        }

        $sawUnknown = false;
        foreach ($expectations as $expectation) {
            $observed = ($this->records ?? new DohDnsRecordResolver)->lookup($expectation['host'], 'TXT');
            if ($observed === null) {
                $sawUnknown = true;

                continue;
            }

            $matched = false;
            foreach ($observed as $record) {
                $txt = $this->recordText($record);
                if ($this->publicKey($txt) === null) {
                    continue;
                }

                $expectedKey = $this->publicKey($expectation['value']);
                $observedKey = $this->publicKey($txt);
                if (hash_equals($expectedKey, $observedKey)) {
                    $matched = true;

                    break;
                }
            }

            if (! $matched) {
                return 'missing';
            }
        }

        return $sawUnknown ? 'unknown' : 'pass';
    }

    /**
     * @param  array<string, mixed>  $records
     * @return list<array{host: string, value: ?string}>
     */
    private function expectedDkimRecords(string $domain, array $records): array
    {
        $found = [];
        $collect = function (mixed $value, string $key = '', bool $dkimContext = false) use (&$collect, &$found, $domain): void {
            if (is_array($value)) {
                $dkimContext = $dkimContext || str_contains(strtolower($key), 'dkim');
                $host = $value['host'] ?? $value['name'] ?? null;
                $recordValue = $value['value'] ?? $value['txt'] ?? $value['data'] ?? null;
                $selector = $value['selector'] ?? $value['dkim_selector'] ?? null;
                $publicKey = $value['public_key'] ?? $value['publicKey'] ?? null;
                if ($dkimContext && ! is_string($host) && is_string($selector)
                    && preg_match('/\A[a-z0-9_-]+\z/i', trim($selector)) === 1) {
                    $host = $selector.'._domainkey.'.$domain;
                } elseif ($dkimContext && is_string($host)
                    && ! str_contains(strtolower($host), '._domainkey.')
                    && preg_match('/\A[a-z0-9_-]+\z/i', trim($host, " .\t\n\r\0\x0B")) === 1) {
                    $host .= '._domainkey.'.$domain;
                }
                if ($dkimContext && ! is_string($recordValue) && is_string($publicKey)) {
                    $recordValue = str_starts_with(strtolower(trim($publicKey)), 'v=dkim1')
                        ? $publicKey
                        : 'v=DKIM1; p='.$publicKey;
                }
                if ($dkimContext
                    && is_string($host)
                    && ($normalisedHost = $this->normaliseDkimHost($host, $domain)) !== null) {
                    $found[$normalisedHost] = [
                        'host' => $normalisedHost,
                        'value' => is_string($recordValue) ? trim($recordValue) : null,
                    ];
                }

                foreach ($value as $childKey => $childValue) {
                    $collect($childValue, (string) $childKey, $dkimContext);
                }

                return;
            }

            if (is_string($value) && str_contains(strtolower($key), 'zone_file')) {
                foreach ($this->dkimRecordsFromZoneFile($domain, $value) as $record) {
                    // Provider-structured records are authoritative. Zone files
                    // are a fallback and may arrive flattened onto one line.
                    $found[$record['host']] ??= $record;
                }
            }
        };

        $collect($records);

        $selector = $records['dkim_selector'] ?? null;
        $publicKey = $records['dkim_public_key'] ?? $records['dkim_publicKey'] ?? null;
        if (is_string($selector) && is_string($publicKey)) {
            $host = $this->normaliseDkimHost($selector.'._domainkey.'.$domain, $domain);
            if ($host !== null) {
                $found[$host] = [
                    'host' => $host,
                    'value' => str_starts_with(strtolower(trim($publicKey)), 'v=dkim1')
                        ? trim($publicKey)
                        : 'v=DKIM1; p='.trim($publicKey),
                ];
            }
        }

        return array_values($found);
    }

    /** @return list<array{host: string, value: ?string}> */
    private function dkimRecordsFromZoneFile(string $domain, string $zoneFile): array
    {
        $records = [];
        $zoneFiles = $this->zoneFiles ?? new DnsZoneFileParser;
        foreach ($zoneFiles->records($zoneFile) as $record) {
            if ($record['type'] !== 'TXT') {
                continue;
            }

            $host = $this->normaliseDkimHost($record['host'], $domain);
            $value = $zoneFiles->txtValue($record['value']);
            if ($host !== null && $value !== null && str_starts_with(strtolower($value), 'v=dkim1')) {
                $records[] = ['host' => $host, 'value' => $value];
            }
        }

        return $records;
    }

    private function publicKey(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim($value, " \t\n\r\0\x0B\"");
        if (preg_match('/\Av=dkim1(?:\s*;|\s|$)/i', $value) !== 1) {
            return null;
        }

        foreach (explode(';', $value) as $part) {
            $part = trim($part);
            if (preg_match('/\Ap\s*=\s*(.*)\z/i', $part, $matches) === 1) {
                $key = trim((string) $matches[1]);

                return $key === '' ? null : $key;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $record */
    private function recordText(array $record): string
    {
        $value = $record['txt'] ?? $record['data'] ?? $record['value'] ?? '';
        if (! is_string($value)) {
            return '';
        }

        return trim($value, " \t\r\n\"");
    }

    private function normaliseHost(string $host): ?string
    {
        $host = strtolower(trim($host, " .\t\n\r\0\x0B"));

        return $host !== '' && $this->isDnsName($host)
            ? $host
            : null;
    }

    private function isDnsName(string $host): bool
    {
        $labels = explode('.', $host);
        if (count($labels) > 127) {
            return false;
        }

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63
                || preg_match('/\A[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\z/i', $label) !== 1) {
                return false;
            }
        }

        return strlen($host) <= 253;
    }

    private function normaliseDkimHost(string $host, string $domain): ?string
    {
        $host = strtolower(trim($host, " .\t\n\r\0\x0B"));
        if ($host === '@') {
            $host = $domain;
        } elseif (! str_contains($host, '.')) {
            $host .= '.'.$domain;
        } elseif (str_ends_with($host, '._domainkey')) {
            $host .= '.'.$domain;
        } elseif (! str_ends_with($host, '.'.$domain) && $host !== $domain) {
            // A fully-qualified selector outside the managed domain is not a
            // relative record name and must not be rewritten into this zone.
            return null;
        }

        if (! str_contains($host, '._domainkey.')) {
            return null;
        }

        return $this->normaliseHost($host);
    }
}
