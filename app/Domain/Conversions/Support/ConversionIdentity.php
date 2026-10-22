<?php

namespace App\Domain\Conversions\Support;

use App\Domain\People\Support\DomainNormalizer;
use Illuminate\Validation\ValidationException;

/** Normalises and hashes the only identity forms supported by S2. */
final class ConversionIdentity
{
    /** @var list<string> */
    public const TYPES = ['account_id', 'external_identity', 'domain'];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public static function normalize(array $input): array
    {
        $type = strtolower(trim((string) ($input['type'] ?? $input['identity_type'] ?? $input['key'] ?? '')));
        if ($type === 'account') {
            $type = 'account_id';
        }
        if ($type === 'account_external_id' || $type === 'external_id') {
            $type = 'external_identity';
        }
        if ($type === 'account_domain' || $type === 'domain_normalized') {
            $type = 'domain';
        }
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['identities' => 'Only exact Account identity types are supported.']);
        }

        if ($type === 'account_id') {
            $value = $input['value'] ?? ($input['account_id'] ?? null);
            if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
                $value = (int) $value;
            }
            if (! is_int($value) || $value <= 0) {
                throw ValidationException::withMessages(['identities.account_id' => 'Account IDs must be positive integers.']);
            }

            return [
                'type' => $type,
                'value' => $value,
                'value_hash' => hash('sha256', (string) $value),
            ];
        }

        if ($type === 'domain') {
            $domain = DomainNormalizer::normalize((string) ($input['value'] ?? ($input['domain'] ?? '')));
            if ($domain === null || DomainNormalizer::isFreeMailDomain($domain)) {
                throw ValidationException::withMessages(['identities.domain' => 'A public business domain is required.']);
            }

            return [
                'type' => $type,
                'value' => $domain,
                'value_hash' => hash('sha256', $domain),
            ];
        }

        $provider = trim((string) ($input['provider'] ?? $input['namespace'] ?? ''));
        $identityType = trim((string) ($input['identity_type'] ?? $input['external_type'] ?? ''));
        $value = trim((string) ($input['value'] ?? ''));
        if ($provider === '' || mb_strlen($provider) > 100 || $identityType === '' || mb_strlen($identityType) > 80 || $value === '' || mb_strlen($value) > 1000) {
            throw ValidationException::withMessages(['identities.external_identity' => 'External Account identities require bounded provider, type and value fields.']);
        }
        $normalized = mb_strtolower($value);

        return [
            'type' => $type,
            'provider' => $provider,
            'identity_type' => $identityType,
            'value' => $normalized,
            'value_hash' => hash('sha256', $normalized),
        ];
    }

    /**
     * @param  array<string,mixed>  $identity
     * @return array<string,mixed>
     */
    public static function safeHandle(array $identity): array
    {
        $result = [
            'type' => $identity['type'],
            'value_hash' => $identity['value_hash'],
        ];
        foreach (['provider', 'identity_type'] as $field) {
            if (isset($identity[$field])) {
                $result[$field] = $identity[$field];
            }
        }
        if ($identity['type'] === 'domain') {
            // A normalised public domain is safe to use as a lookup handle;
            // unmatched raw payloads remain in the encrypted receipt envelope.
            $result['domain'] = $identity['value'];
        }
        if ($identity['type'] === 'account_id') {
            $result['account_id'] = $identity['value'];
        }

        return $result;
    }
}
