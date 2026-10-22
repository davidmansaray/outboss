<?php

namespace App\Domain\Integrations\Operations;

use App\Domain\Integrations\Contracts\CapabilityRequest;
use App\Domain\Integrations\DTOs\CapabilityDefinition;
use App\Domain\Integrations\DTOs\ExternalCostEstimate;
use App\Models\IntegrationProviderRegistration;

final class IntegrationCostEstimator
{
    public function estimate(
        IntegrationProviderRegistration $registration,
        CapabilityDefinition $definition,
        CapabilityRequest $request,
    ): ExternalCostEstimate {
        $limits = is_array($registration->limits) ? $registration->limits : [];
        $configuredCost = $limits['cost_micros'] ?? 1_000;
        $costMicros = is_int($configuredCost) || (is_string($configuredCost) && ctype_digit($configuredCost))
            ? max(0, (int) $configuredCost)
            : 1_000;
        $configuredUnits = $limits['native_units'] ?? null;
        $nativeUnits = is_int($configuredUnits) || (is_string($configuredUnits) && ctype_digit((string) $configuredUnits))
            ? max(0, (int) $configuredUnits)
            : $this->inputUnits($request, $definition);
        $nativeUnit = isset($limits['native_unit']) && is_string($limits['native_unit'])
            ? mb_substr($limits['native_unit'], 0, 32)
            : 'requests';
        $basis = isset($limits['cost_basis']) && is_string($limits['cost_basis'])
            ? mb_substr($limits['cost_basis'], 0, 48)
            : 'upper_bound_estimate';

        return new ExternalCostEstimate($nativeUnits, $nativeUnit, $costMicros, $basis, true);
    }

    private function inputUnits(CapabilityRequest $request, CapabilityDefinition $definition): int
    {
        foreach (['account_ids', 'person_ids', 'emails', 'urls', 'records', 'events'] as $key) {
            if (isset($request->input[$key]) && is_array($request->input[$key])) {
                return max(1, min(count($request->input[$key]), $definition->maxInputItems));
            }
        }

        return 1;
    }
}
