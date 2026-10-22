<?php

namespace App\Domain\Intelligence\DTOs;

use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;

/** Immutable application input for starting one Account run. */
final readonly class StartAccountResearchRequest
{
    /**
     * @param  array<string,mixed>  $freshnessPolicy
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public Workspace $workspace,
        public Account|int|string $account,
        public ?User $actor,
        public string $idempotencyKey,
        public ?string $gtmProfileVersionId = null,
        public ?string $offerVersionId = null,
        public array $freshnessPolicy = [],
        public array $capabilities = [],
        public int $maximumCostMicros = 1_500_000,
        public ?string $preflightHash = null,
        public ?string $parentRunId = null,
    ) {}
}
