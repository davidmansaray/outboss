<?php

namespace App\Domain\Intelligence\DTOs;

use App\Models\Account;

/**
 * Side-effect-free admission result for a single Account research run.
 *
 * The DTO intentionally contains only bounded metadata. It is safe to return
 * to a browser/API caller and can be signed by a transport without embedding
 * source text or credentials.
 */
final readonly class AccountResearchPreflight
{
    /**
     * @param  list<array{code:string, message:string}>  $blockers
     * @param  list<array<string,mixed>>  $stages
     * @param  array<string,mixed>  $accountSnapshot
     * @param  array<string,mixed>  $policySnapshot
     * @param  array<string,mixed>  $capabilitySnapshot
     * @param  array<string,mixed>  $freshnessPolicy
     */
    public function __construct(
        public Account $account,
        public string $inputHash,
        public bool $blocked,
        public array $blockers,
        public array $stages,
        public array $accountSnapshot,
        public array $policySnapshot,
        public array $capabilitySnapshot,
        public array $freshnessPolicy,
        public int $estimatedCostMicros,
        public int $maximumCostMicros,
        public string $currency = 'USD',
        public ?string $gtmProfileVersionId = null,
        public ?string $offerVersionId = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'account_id' => (int) $this->account->id,
            'input_hash' => $this->inputHash,
            'blocked' => $this->blocked,
            'blockers' => $this->blockers,
            'stages' => $this->stages,
            'account_snapshot' => $this->accountSnapshot,
            'policy_snapshot' => $this->policySnapshot,
            'capability_snapshot' => $this->capabilitySnapshot,
            'freshness_policy' => $this->freshnessPolicy,
            'estimated_cost_micros' => $this->estimatedCostMicros,
            'maximum_cost_micros' => $this->maximumCostMicros,
            'currency' => $this->currency,
            'gtm_profile_version_id' => $this->gtmProfileVersionId,
            'offer_version_id' => $this->offerVersionId,
        ];
    }
}
