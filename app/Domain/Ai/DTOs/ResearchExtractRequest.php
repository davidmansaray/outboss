<?php

namespace App\Domain\Ai\DTOs;

final readonly class ResearchExtractRequest
{
    /** @param list<string> $urls */
    public function __construct(
        public array $urls,
        public string $depth = 'basic',
        public int $timeoutSeconds = 30,
    ) {}
}
