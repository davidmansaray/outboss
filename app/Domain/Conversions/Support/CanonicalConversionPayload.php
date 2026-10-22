<?php

namespace App\Domain\Conversions\Support;

/**
 * Small canonicalisation helper shared by definition and event fingerprints.
 * JSON object key order is not part of either contract, while list order is
 * meaningful and therefore deliberately preserved.
 */
final class CanonicalConversionPayload
{
    public static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonicalize($item), $value);
        }

        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $canonical = [];
        foreach ($keys as $key) {
            $canonical[(string) $key] = self::canonicalize($value[$key]);
        }

        return $canonical;
    }

    public static function json(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::json($value));
    }
}
