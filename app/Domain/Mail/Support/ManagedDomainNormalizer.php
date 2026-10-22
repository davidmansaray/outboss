<?php

namespace App\Domain\Mail\Support;

use App\Domain\Mail\Enums\ManagedMailDomainKind;
use InvalidArgumentException;

/**
 * Canonicalise domains used as managed-mail principals.
 *
 * This is intentionally stricter than the general website normaliser. A
 * managed mail domain must be a public, registrable DNS name; localhost,
 * private/IP literals, reserved test suffixes, and bare public suffixes are
 * rejected before a remote management call can be made.
 */
final class ManagedDomainNormalizer
{
    /**
     * Common multi-label public suffixes needed for deterministic local
     * validation. This is not a replacement for a periodically updated PSL;
     * production DNS verification remains the final readiness gate.
     *
     * @var list<string>
     */
    private const MULTI_LABEL_PUBLIC_SUFFIXES = [
        'ac.uk',
        'co.jp',
        'co.nz',
        'co.uk',
        'com.au',
        'com.br',
        'com.cn',
        'com.mx',
        'com.sg',
        'com.tr',
        'com.tw',
        'edu.au',
        'gov.au',
        'gov.uk',
        'net.au',
        'net.nz',
        'org.au',
        'org.uk',
    ];

    /**
     * Reserved/local suffixes must never be provisioned as public mail
     * domains. `example.com` remains valid as a syntactic fixture domain;
     * only RFC 2606's reserved TLDs are rejected here.
     *
     * @var list<string>
     */
    private const RESERVED_SUFFIXES = [
        'example',
        'internal',
        'invalid',
        'localhost',
        'local',
        'test',
    ];

    public static function normalize(?string $input): ?string
    {
        $value = trim((string) $input);
        if ($value === '' || preg_match('/[\x00-\x20\x7f]/u', $value) === 1) {
            return null;
        }

        // Accept a pasted hostname/URL for operator convenience, but never
        // retain path, query, credentials, port, or a trailing dot.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1 || str_starts_with($value, '//')) {
            $parsed = parse_url(str_starts_with($value, '//') ? 'https:'.$value : $value);
            $value = is_array($parsed) && is_string($parsed['host'] ?? null)
                ? $parsed['host']
                : '';
        } else {
            $value = explode('/', $value, 2)[0];
            $value = explode('?', $value, 2)[0];
            $value = explode('#', $value, 2)[0];
        }

        $value = strtolower(trim($value, " \t\n\r\0\x0B."));
        if ($value === '' || str_contains($value, '@') || str_contains($value, ':')) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii(
                $value,
                IDNA_DEFAULT | (defined('IDNA_USE_STD3_RULES') ? IDNA_USE_STD3_RULES : 0),
                defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 1,
            );
            if (! is_string($ascii) || $ascii === '') {
                return null;
            }
            $value = strtolower(rtrim($ascii, '.'));
        }

        if (strlen($value) > 253 || ! str_contains($value, '.')) {
            return null;
        }

        $labels = explode('.', $value);
        if (count($labels) < 2 || in_array(end($labels), self::RESERVED_SUFFIXES, true)) {
            return null;
        }

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^(?!-)[a-z0-9-]+(?<!-)$/', $label) !== 1) {
                return null;
            }
        }

        $organisational = self::organisationalDomainFromLabels($labels);
        if ($organisational === null) {
            return null;
        }

        return $value;
    }

    public static function normalizeOrFail(string $input): string
    {
        return self::normalize($input)
            ?? throw new InvalidArgumentException('The managed mail domain is not a valid public DNS name.');
    }

    public static function organisationalDomain(string $domain): ?string
    {
        $normalized = self::normalize($domain);
        if ($normalized === null) {
            return null;
        }

        return self::organisationalDomainFromLabels(explode('.', $normalized));
    }

    /** American-English alias for callers using that spelling. */
    public static function organizationalDomain(string $domain): ?string
    {
        return self::organisationalDomain($domain);
    }

    public static function kind(string $domain): ManagedMailDomainKind
    {
        $normalized = self::normalizeOrFail($domain);
        $organisational = self::organisationalDomain($normalized);

        return $organisational === $normalized
            ? ManagedMailDomainKind::Root
            : ManagedMailDomainKind::Subdomain;
    }

    public static function normalizeLocalPart(?string $input): ?string
    {
        $value = strtolower(trim((string) $input));

        if ($value === '' || strlen($value) > 64 || str_contains($value, '@')) {
            return null;
        }

        // Managed principals use a conservative dot-atom local part. Quoted
        // local parts and control/unicode characters are deliberately not
        // accepted because they make provisioning and DNS evidence opaque.
        if (preg_match('/^[a-z0-9!#$%&\'*+\/?^_`{|}~-]+(?:\.[a-z0-9!#$%&\'*+\/?^_`{|}~-]+)*$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    public static function normalizeLocalPartOrFail(string $input): string
    {
        return self::normalizeLocalPart($input)
            ?? throw new InvalidArgumentException('The managed mailbox local part is invalid.');
    }

    /**
     * @param  list<string>  $labels
     */
    private static function organisationalDomainFromLabels(array $labels): ?string
    {
        $domain = implode('.', $labels);
        $suffix = null;

        foreach (self::MULTI_LABEL_PUBLIC_SUFFIXES as $candidate) {
            if ($domain === $candidate || str_ends_with($domain, '.'.$candidate)) {
                if ($suffix === null || strlen($candidate) > strlen($suffix)) {
                    $suffix = $candidate;
                }
            }
        }

        if ($suffix !== null) {
            $suffixLabels = substr_count($suffix, '.') + 1;
            if (count($labels) <= $suffixLabels) {
                return null;
            }

            return implode('.', array_slice($labels, -($suffixLabels + 1)));
        }

        // The ordinary DNS case: the last label is the public suffix and the
        // preceding label is the registrable organisation. A full PSL-backed
        // implementation can replace this helper without changing callers.
        if (count($labels) < 2) {
            return null;
        }

        return implode('.', array_slice($labels, -2));
    }
}
