<?php

namespace App\Domain\People\Support;

class DomainNormalizer
{
    /**
     * @var list<string>
     */
    private const FREE_MAIL_DOMAINS = [
        'gmail.com',
        'googlemail.com',
        'outlook.com',
        'hotmail.com',
        'live.com',
        'yahoo.com',
        'icloud.com',
        'me.com',
        'aol.com',
        'proton.me',
        'protonmail.com',
        'gmx.com',
        'mail.com',
        'zoho.com',
        'yandex.com',
    ];

    /**
     * Normalise a user-entered website or domain to a bare registrable-ish domain, or null.
     */
    public static function normalize(?string $input): ?string
    {
        $value = strtolower(trim((string) $input));

        if ($value === '') {
            return null;
        }

        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = preg_replace('#^//#', '', $value) ?? $value;
        $value = explode('#', $value, 2)[0];
        $value = explode('?', $value, 2)[0];
        $value = explode('/', $value, 2)[0];

        if (str_contains($value, '@')) {
            $value = (string) substr($value, strrpos($value, '@') + 1);
        }

        if (preg_match('/^(.+):(\d+)$/', $value, $matches) === 1) {
            $value = $matches[1];
        }

        if (str_starts_with($value, 'www.')) {
            $value = substr($value, 4);
        }

        $value = trim($value, ". \t\n\r\0\x0B");

        if ($value === '' || ! str_contains($value, '.')) {
            return null;
        }

        return $value;
    }

    /**
     * True when the domain is a consumer mail provider that must not identify an account.
     */
    public static function isFreeMailDomain(string $domain): bool
    {
        $normalized = self::normalize($domain) ?? strtolower(trim($domain));

        return in_array($normalized, self::FREE_MAIL_DOMAINS, true);
    }

    /**
     * Extract and normalise the domain part of an email address, or null.
     */
    public static function fromEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        $domain = substr($email, strrpos($email, '@') + 1);

        return self::normalize($domain);
    }
}
