<?php

namespace App\Domain\Ai\DTOs;

final readonly class LlmResult
{
    public function __construct(
        public string $content,
        public int $inputTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public int $cacheTokens,
        public int $costMicros,
        public string $costBasis,
        public ?string $providerRequestId,
        public ?string $providerGenerationId,
        /** Exact decimal provider cost as returned by the provider. */
        public ?string $rawProviderCost = null,
        /** The opaque JSON response id, intentionally distinct from generation id. */
        public ?string $providerResponseId = null,
        /** Total token count when the provider supplied one. */
        public ?int $totalTokens = null,
        /** @var array<string, mixed> */
        public array $providerMetadata = [],
    ) {}
}
