<?php

namespace App\Domain\Objectives\DTOs;

use App\Domain\Objectives\Enums\ObjectiveRunState;

final readonly class ObjectiveDraftingResult
{
    public function __construct(
        public ObjectiveRunState $runState,
        public string $outputHash,
        public ?string $waitingReason = null,
    ) {}
}
