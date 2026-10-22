<?php

namespace App\Domain\Ai\DTOs;

final readonly class ResearchResult
{
    /**
     * @param  list<array<string, mixed>>  $results
     * @param  list<array<string, mixed>>  $failedResults
     * @param  array<string, mixed>  $providerMetadata
     */
    public function __construct(
        public array $results,
        public int $credits,
        public ?string $providerRequestId,
        public ?int $reportedCredits = null,
        public array $failedResults = [],
        public ?float $responseTime = null,
        public array $providerMetadata = [],
    ) {}
}
