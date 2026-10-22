<?php

namespace App\Domain\Intelligence\DTOs;

/** Structured claim emitted by a provider or deterministic evaluator. */
final readonly class ResearchClaimInput
{
    /**
     * @param  scalar|array<string,mixed>|list<mixed>|null  $value
     * @param  list<array{snapshot_id:string, edge_type?:string, locator?:string, quote_hash?:string, weight?:float|int}>  $evidence
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public string $predicate,
        public mixed $value,
        public string $valueType,
        public string $status = 'supported',
        public float $confidence = 0.0,
        public ?string $calibrationBand = null,
        public array $evidence = [],
        public ?string $observedAt = null,
        public ?string $freshUntil = null,
        public ?string $expiresAt = null,
        public string $extractorKind = 'deterministic',
        public string $extractorVersion = '1',
        public bool $safeForMessage = false,
        public array $metadata = [],
    ) {}
}
