<?php

namespace App\Domain\Objectives\Services;

use App\Domain\Objectives\Enums\ObjectiveTargetKind;
use App\Models\AudienceSnapshot;
use Illuminate\Validation\ValidationException;

final class ForecastObjectiveInventory
{
    /**
     * @param  array<string, mixed>  $assumptions
     * @return array<string, mixed>
     */
    public function handle(
        AudienceSnapshot $snapshot,
        ObjectiveTargetKind $targetKind,
        ?int $targetCount,
        ?string $targetValue,
        ?string $currency,
        array $assumptions,
    ): array {
        $rates = [];
        foreach (['low', 'base', 'high'] as $band) {
            $value = $assumptions['reply_to_conversion_rate'][$band] ?? null;
            if ($value === null || $value === '') {
                $rates[$band] = null;

                continue;
            }
            if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
                throw ValidationException::withMessages([
                    "forecast_assumptions.reply_to_conversion_rate.{$band}" => 'Use a rate between 0 and 1.',
                ]);
            }
            $rates[$band] = round((float) $value, 6);
        }
        if ($rates['low'] !== null && $rates['base'] !== null && $rates['high'] !== null
            && ! ($rates['low'] <= $rates['base'] && $rates['base'] <= $rates['high'])) {
            throw ValidationException::withMessages([
                'forecast_assumptions.reply_to_conversion_rate' => 'Low, base and high assumptions must be ordered.',
            ]);
        }

        $averageValue = null;
        if ($targetKind === ObjectiveTargetKind::Value) {
            $rawAverage = $assumptions['average_conversion_value'] ?? null;
            $assumptionCurrency = strtoupper(trim((string) ($assumptions['currency'] ?? '')));
            if (! is_numeric($rawAverage) || (float) $rawAverage <= 0 || $assumptionCurrency !== $currency) {
                throw ValidationException::withMessages([
                    'forecast_assumptions.average_conversion_value' => 'A positive average value in the Objective currency is required.',
                ]);
            }
            $averageValue = round((float) $rawAverage, 2);
        }

        $people = (int) $snapshot->included_people_count;
        $projections = [];
        foreach ($rates as $band => $rate) {
            if ($rate === null) {
                $projections[$band] = ['status' => 'unknown'];

                continue;
            }
            $conversions = round($people * $rate, 4);
            $projectedValue = $averageValue === null ? null : round($conversions * $averageValue, 2);
            $target = $targetKind === ObjectiveTargetKind::Count ? (float) $targetCount : (float) $targetValue;
            $projected = $targetKind === ObjectiveTargetKind::Count ? $conversions : (float) $projectedValue;
            $projections[$band] = [
                'status' => 'estimated',
                'conversion_rate' => $rate,
                'conversions' => $conversions,
                'value' => $projectedValue,
                'contacts_required' => $rate > 0
                    ? (int) ceil(($targetKind === ObjectiveTargetKind::Value ? $target / (float) $averageValue : $target) / $rate)
                    : null,
                'shortfall' => round(max(0, $target - $projected), 2),
            ];
        }

        return [
            'version' => 'objective_forecast_v1',
            'included_people' => $people,
            'included_accounts' => (int) $snapshot->included_accounts_count,
            'executable_people' => $people,
            'one_message_inventory' => $people,
            'currency' => $currency,
            'assumption_ranges' => $projections,
        ];
    }
}
