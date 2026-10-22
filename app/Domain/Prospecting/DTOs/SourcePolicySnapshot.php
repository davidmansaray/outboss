<?php

namespace App\Domain\Prospecting\DTOs;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Immutable, hashed policy data copied onto every source version and
 * observation. The DTO deliberately exposes only policy metadata, not raw
 * source/provider payloads.
 */
final readonly class SourcePolicySnapshot
{
    /** @param array<string, mixed> $policy */
    public function __construct(
        public array $policy = [],
        ?string $hash = null,
    ) {
        $this->hash = $hash ?? self::hashPolicy($policy);
    }

    public string $hash;

    /** @param array<string, mixed> $policy */
    public static function from(array $policy): self
    {
        return new self(self::canonicalize($policy));
    }

    public static function native(): self
    {
        return self::from([
            'provenance_class' => 'native',
            'provider' => null,
            'contract_version' => null,
            'allowed_purposes' => ['prospecting'],
            'prohibited_uses' => [],
            'geography' => null,
            'personal_data_classification' => 'standard',
            'display_fields' => [],
            'display_requirements' => [],
            'display_classification' => 'standard',
            'raw_retention_days' => 0,
            'normalized_retention_days' => 365,
            'export_allowed' => true,
            'export_fields' => [],
            'export_classification' => 'standard',
            'deletion_behavior' => 'delete_source_data',
            'contact_eligibility' => 'eligible',
            'policy_reviewed_at' => null,
            'policy_expires_at' => null,
            'approval_reference' => null,
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->policy;
    }

    public function allowsExport(): bool
    {
        return (bool) ($this->policy['export_allowed'] ?? false);
    }

    public function isExpired(?DateTimeInterface $at = null): bool
    {
        $expiry = $this->policy['policy_expires_at'] ?? null;
        if (! is_string($expiry) || trim($expiry) === '') {
            return false;
        }

        try {
            return new DateTimeImmutable($expiry) <= ($at ?? new DateTimeImmutable);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    public static function canonicalize(array $policy): array
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    return array_map($normalize, $value);
                }
                ksort($value);

                return array_map($normalize, $value);
            }

            if ($value instanceof DateTimeInterface) {
                return $value->format(DateTimeInterface::ATOM);
            }

            return $value;
        };

        $canonical = $normalize($policy);
        if (! is_array($canonical)) {
            return [];
        }

        /** @var array<string, mixed> $canonical */
        return $canonical;
    }

    /** @param array<string, mixed> $policy */
    public static function hashPolicy(array $policy): string
    {
        return hash('sha256', json_encode(self::canonicalize($policy), JSON_THROW_ON_ERROR));
    }
}
