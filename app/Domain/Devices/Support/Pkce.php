<?php

namespace App\Domain\Devices\Support;

/**
 * RFC 7636 proof key helpers. Only the S256 method is accepted.
 */
final class Pkce
{
    public const CHALLENGE_PATTERN = '/^[A-Za-z0-9_-]{43,128}$/';

    public const VERIFIER_PATTERN = '/^[A-Za-z0-9\-._~]{43,128}$/';

    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function verifies(string $verifier, string $challenge): bool
    {
        return preg_match(self::VERIFIER_PATTERN, $verifier) === 1
            && hash_equals($challenge, self::challengeFor($verifier));
    }
}
