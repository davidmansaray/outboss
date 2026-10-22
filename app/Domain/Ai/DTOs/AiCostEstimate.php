<?php

namespace App\Domain\Ai\DTOs;

final readonly class AiCostEstimate
{
    public function __construct(
        public int $nativeUnits,
        public string $nativeUnit,
        public int $costMicros,
    ) {}
}
