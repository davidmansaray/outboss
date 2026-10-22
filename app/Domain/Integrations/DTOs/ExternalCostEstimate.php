<?php

namespace App\Domain\Integrations\DTOs;

final readonly class ExternalCostEstimate
{
    public function __construct(
        public int $nativeUnits,
        public string $nativeUnit,
        public int $costMicros,
        public string $costBasis = 'formula_estimate',
        public bool $upperBound = true,
    ) {
        if ($nativeUnits < 0 || $costMicros < 0) {
            throw new \InvalidArgumentException('External cost estimates cannot be negative.');
        }
    }

    public static function free(): self
    {
        return new self(0, 'requests', 0, 'zero_cost');
    }
}
