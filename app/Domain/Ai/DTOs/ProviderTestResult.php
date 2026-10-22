<?php

namespace App\Domain\Ai\DTOs;

final readonly class ProviderTestResult
{
    public function __construct(
        public int $nativeUnits,
        public string $nativeUnit,
        public int $costMicros,
        public string $costBasis,
        public ?string $providerRequestId = null,
        public ?string $providerGenerationId = null,
        public ?string $balanceSummary = null,
        /** Exact decimal provider cost, when the provider supplied one. */
        public ?string $rawProviderCost = null,
        /** Evidence source for the normalized cost/credit value. */
        public string $evidenceSource = 'formula_estimate',
        /** @var array<string, mixed> */
        public array $providerMetadata = [],
        /** The opaque response id, separate from the generation id. */
        public ?string $providerResponseId = null,
    ) {}
}
