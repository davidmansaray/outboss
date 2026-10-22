<?php

namespace App\Domain\Gtm\Support;

use InvalidArgumentException;
use JsonException;

/**
 * Canonical representation of the metadata stored in an Asset Version.
 * Binary content is deliberately not part of this payload: S2 supports
 * external HTTPS metadata only.
 */
final class CanonicalAssetPayload
{
    /** @var list<string> */
    public const FIELDS = [
        'title',
        'description',
        'external_url',
        'media_type',
        'source',
        'access_instructions',
        'approval_state',
        'intended_audience',
        'expires_at',
        'availability_state',
        'licence',
        'classification',
        'delivery_restrictions',
        'tracking_metadata',
    ];

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function canonicalize(array $payload): array
    {
        /** @var array<string,mixed> $canonical */
        $canonical = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $canonical[$field] = self::normalizeNode($payload[$field]);
            }
        }

        return $canonical;
    }

    /** @param array<string,mixed> $payload */
    public static function json(array $payload): string
    {
        try {
            return json_encode(
                self::canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The Asset metadata cannot be encoded.', previous: $exception);
        }
    }

    /** @param array<string,mixed> $payload */
    public static function hash(array $payload): string
    {
        return hash('sha256', self::json($payload));
    }

    private static function normalizeNode(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim(str_replace(["\r\n", "\r"], "\n", $value));
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalizeNode($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalizeNode($item);
        }

        return $value;
    }
}
