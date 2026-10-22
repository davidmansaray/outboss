<?php

namespace App\Domain\Ai\DTOs;

final readonly class LlmRequest
{
    /**
     * @param  array<string, mixed>|null  $responseSchema
     */
    public function __construct(
        public string $modelId,
        public string $content,
        public int $maximumOutputTokens,
        public ?string $responseSchemaName = null,
        public ?array $responseSchema = null,
    ) {}
}
