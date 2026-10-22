<?php

namespace App\Domain\Objectives\Support;

use BackedEnum;
use DateTimeInterface;
use Normalizer;

final class CanonicalObjectivePayload
{
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function encode(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    public static function canonicalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if (is_string($value)) {
            $value = str_replace(["\r\n", "\r"], "\n", $value);

            return class_exists(Normalizer::class)
                ? (Normalizer::normalize($value, Normalizer::FORM_C) ?: $value)
                : $value;
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::canonicalize(...), $value);
            }
            ksort($value, SORT_STRING);

            return array_map(self::canonicalize(...), $value);
        }
        if (is_float($value) && ! is_finite($value)) {
            throw new \InvalidArgumentException('Non-finite numbers cannot be canonicalized.');
        }

        return $value;
    }
}
