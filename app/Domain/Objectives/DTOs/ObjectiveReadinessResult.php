<?php

namespace App\Domain\Objectives\DTOs;

final readonly class ObjectiveReadinessResult
{
    /**
     * @param  list<array{code:string,message:string}>  $blockers
     * @param  list<array{code:string,message:string}>  $warnings
     */
    public function __construct(
        public array $blockers,
        public array $warnings,
        public string $inputHash,
    ) {}

    public function ready(): bool
    {
        return $this->blockers === [];
    }
}
