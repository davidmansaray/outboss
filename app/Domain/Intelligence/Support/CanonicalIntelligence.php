<?php

namespace App\Domain\Intelligence\Support;

use DateTimeInterface;
use JsonException;

final class CanonicalIntelligence
{
    /** @param array<string,mixed> $value */
    public static function hash(array $value): string
    {
        return hash('sha256', self::encode($value));
    }

    /** @param array<string,mixed> $value */
    public static function encode(array $value): string
    {
        return json_encode(self::canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function valueHash(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonicalizeValue($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,mixed> */
    public static function decodeObject(string $json): array
    {
        $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($value) || array_is_list($value)) {
            throw new JsonException('The canonical intelligence payload must be an object.');
        }

        /** @var array<string,mixed> $value */
        return $value;
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public static function canonicalize(array $value): array
    {
        /** @var array<string,mixed> $result */
        $result = self::canonicalizeValue($value);

        return $result;
    }

    private static function canonicalizeValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map([self::class, 'canonicalizeValue'], $value);
            }
            ksort($value);

            return array_map([self::class, 'canonicalizeValue'], $value);
        }

        if (is_float($value)) {
            return (float) number_format($value, 8, '.', '');
        }

        return $value;
    }
}
