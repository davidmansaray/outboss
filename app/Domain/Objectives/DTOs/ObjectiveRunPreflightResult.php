<?php

namespace App\Domain\Objectives\DTOs;

final readonly class ObjectiveRunPreflightResult
{
    /**
     * @param  list<array{code:string,message:string}>  $blockers
     * @param  list<array{code:string,message:string}>  $warnings
     * @param  list<array{arm_id:string,input_hash:string,estimated_cost_micros:int}>  $armEstimates
     */
    public function __construct(
        public array $blockers,
        public array $warnings,
        public array $armEstimates,
        public int $estimatedCostMicros,
        public int $effectiveMaximumCostMicros,
        public string $inputHash,
    ) {}

    public function ready(): bool
    {
        return $this->blockers === [];
    }
}
