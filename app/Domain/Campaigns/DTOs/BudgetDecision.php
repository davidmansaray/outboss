<?php

namespace App\Domain\Campaigns\DTOs;

use Carbon\CarbonImmutable;

final readonly class BudgetDecision
{
    public function __construct(
        public bool $allowed,
        public ?string $deferredReason = null,
        public ?CarbonImmutable $retryAt = null,
    ) {}
}
