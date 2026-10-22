<?php

namespace App\Domain\Objectives\DTOs;

final readonly class SegmentEvaluation
{
    /** @param list<array<string, mixed>> $members
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $sourceRights
     */
    public function __construct(
        public array $members,
        public array $counts,
        public array $sourceRights,
        public string $criteriaHash,
        public string $evaluationHash,
    ) {}
}
