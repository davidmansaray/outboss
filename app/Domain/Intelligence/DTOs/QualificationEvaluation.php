<?php

namespace App\Domain\Intelligence\DTOs;

use App\Domain\Intelligence\Enums\QualificationOutcome;

/** Immutable evaluator result before it is written to a decision. */
final readonly class QualificationEvaluation
{
    /**
     * @param  list<string>  $missingFacts
     * @param  list<string>  $disqualifiers
     * @param  list<array{claim_id:string,role:string}>  $claims
     */
    public function __construct(
        public QualificationOutcome $outcome,
        public float $confidence,
        public ?string $calibrationBand,
        public array $missingFacts = [],
        public array $disqualifiers = [],
        public string $explanation = '',
        public array $claims = [],
        public bool $sensitiveInference = false,
        public bool $unsupportedCausality = false,
        public ?string $whyAccount = null,
        public ?string $whyOffer = null,
        public ?string $whyNow = null,
        public ?string $factualBasis = null,
        public ?string $internalRationale = null,
        /** Deterministic blockers must never be sent to the model. */
        public bool $deterministicBlocker = false,
    ) {}
}
