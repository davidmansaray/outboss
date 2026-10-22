<?php

namespace App\Domain\Mail\Support;

final class ConfidenceInterval
{
    /**
     * Wilson score interval for a binomial proportion. Wilson remains useful
     * for the small pilot samples where a normal interval can extend below
     * zero or above one.
     *
     * @return array{estimate: float|null, lower: float|null, upper: float|null, successes: int, trials: int, level: float}
     */
    public static function proportion(int $successes, int $trials, float $level = 0.95): array
    {
        $successes = max(0, $successes);
        $trials = max(0, $trials);
        $level = min(0.999, max(0.5, $level));

        if ($trials === 0) {
            return [
                'estimate' => null,
                'lower' => null,
                'upper' => null,
                'successes' => $successes,
                'trials' => $trials,
                'level' => $level,
            ];
        }

        $successes = min($successes, $trials);
        $p = (float) $successes / $trials;
        $z = self::zScore($level);
        $zSquared = $z * $z;
        $denominator = 1 + ($zSquared / $trials);
        $centre = ($p + ($zSquared / (2 * $trials))) / $denominator;
        $half = ($z / $denominator)
            * sqrt((($p * (1 - $p)) / $trials) + ($zSquared / (4 * $trials * $trials)));

        return [
            'estimate' => $p,
            'lower' => max(0.0, $centre - $half),
            'upper' => min(1.0, $centre + $half),
            'successes' => $successes,
            'trials' => $trials,
            'level' => $level,
        ];
    }

    /**
     * Approximate independent two-proportion interval for pA - pB. The
     * report also includes each Wilson interval so reviewers can recalculate
     * the result with a preferred method.
     *
     * @return array{estimate: float|null, lower: float|null, upper: float|null, standard_error: float|null, level: float, a: array<string, mixed>, b: array<string, mixed>}
     */
    public static function difference(
        int $aSuccesses,
        int $aTrials,
        int $bSuccesses,
        int $bTrials,
        float $level = 0.95,
    ): array {
        $a = self::proportion($aSuccesses, $aTrials, $level);
        $b = self::proportion($bSuccesses, $bTrials, $level);

        if ($a['estimate'] === null || $b['estimate'] === null) {
            return [
                'estimate' => null,
                'lower' => null,
                'upper' => null,
                'standard_error' => null,
                'level' => $level,
                'a' => $a,
                'b' => $b,
            ];
        }

        $aP = $a['estimate'];
        $bP = $b['estimate'];
        $standardError = sqrt(
            ($aP * (1 - $aP) / max(1, $aTrials))
            + ($bP * (1 - $bP) / max(1, $bTrials)),
        );
        $estimate = $aP - $bP;
        $margin = self::zScore($level) * $standardError;

        return [
            'estimate' => $estimate,
            'lower' => max(-1.0, $estimate - $margin),
            'upper' => min(1.0, $estimate + $margin),
            'standard_error' => $standardError,
            'level' => $level,
            'a' => $a,
            'b' => $b,
        ];
    }

    /** @return array{absolute_difference: float|null, relative_difference: float|null, odds_ratio: float|null} */
    public static function effectSize(
        int $aSuccesses,
        int $aTrials,
        int $bSuccesses,
        int $bTrials,
    ): array {
        $a = $aTrials > 0 ? (float) $aSuccesses / $aTrials : null;
        $b = $bTrials > 0 ? (float) $bSuccesses / $bTrials : null;

        if ($a === null || $b === null) {
            return [
                'absolute_difference' => null,
                'relative_difference' => null,
                'odds_ratio' => null,
            ];
        }

        $aFailures = max(0, $aTrials - $aSuccesses);
        $bFailures = max(0, $bTrials - $bSuccesses);
        $oddsRatio = (($aSuccesses + 0.5) * ($bFailures + 0.5))
            / (($aFailures + 0.5) * ($bSuccesses + 0.5));

        return [
            'absolute_difference' => $a - $b,
            'relative_difference' => $b == 0.0 ? null : ($a - $b) / $b,
            'odds_ratio' => $oddsRatio,
        ];
    }

    private static function zScore(float $level): float
    {
        // Exact values for the confidence levels used by the pilot. Keeping
        // this dependency-free avoids a statistics package in the runtime.
        return match (true) {
            $level >= 0.995 => 2.807,
            $level >= 0.99 => 2.576,
            $level >= 0.975 => 2.241,
            default => 1.96,
        };
    }
}
