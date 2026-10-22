<?php

namespace App\Domain\Ai\DTOs;

final readonly class ResearchSearchRequest
{
    public function __construct(
        public string $query,
        public string $depth = 'basic',
        public int $maximumResults = 5,
        public int $timeoutSeconds = 30,
    ) {}
}
