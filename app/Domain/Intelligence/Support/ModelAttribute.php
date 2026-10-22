<?php

namespace App\Domain\Intelligence\Support;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Normalize values returned by Eloquent's dynamic attribute interface. */
final class ModelAttribute
{
    public static function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return is_string($value->value) ? $value->value : (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }

    public static function instant(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }
        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value);
        }

        return null;
    }

    /** @return array<string,mixed> */
    public static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
