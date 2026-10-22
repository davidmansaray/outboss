<?php

namespace App\Domain\Ai\Support;

use InvalidArgumentException;

final class UsdMicros
{
    public static function parse(string $amount): int
    {
        $normalized = trim($amount);
        if (preg_match('/^\d+(?:\.\d{1,6})?$/', $normalized) !== 1) {
            throw new InvalidArgumentException('USD amounts must use at most six decimal places.');
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $wholeDollars = (int) $whole;
        if (strlen(ltrim($whole, '0')) > 13 || $wholeDollars > intdiv(PHP_INT_MAX, 1_000_000)) {
            throw new InvalidArgumentException('The USD amount is outside the supported range.');
        }
        $micros = ($wholeDollars * 1_000_000) + (int) str_pad($fraction, 6, '0');

        return $micros;
    }

    public static function format(int $micros, int $decimals = 2): string
    {
        return number_format($micros / 1_000_000, $decimals, '.', '');
    }

    /**
     * Convert a non-negative provider decimal to USD microdollars without
     * floating-point rounding. Provider costs commonly contain more than six
     * fractional digits, so values are conservatively rounded upward.
     */
    public static function ceilMicros(string|int|float $amount): int
    {
        $normalized = trim((string) $amount);
        if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $normalized) !== 1) {
            throw new InvalidArgumentException('USD amounts must be non-negative decimals.');
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $wholeDollars = (int) $whole;
        if (strlen(ltrim($whole, '0')) > 13 || $wholeDollars > intdiv(PHP_INT_MAX, 1_000_000)) {
            throw new InvalidArgumentException('The USD amount is outside the supported range.');
        }

        $microFraction = (int) str_pad(substr($fraction, 0, 6), 6, '0');
        $hasRemainder = strlen($fraction) > 6 && trim(substr($fraction, 6), '0') !== '';
        $micros = ($wholeDollars * 1_000_000) + $microFraction;
        if ($hasRemainder) {
            if ($micros === PHP_INT_MAX) {
                throw new InvalidArgumentException('The USD amount is outside the supported range.');
            }
            $micros++;
        }

        return $micros;
    }
}
