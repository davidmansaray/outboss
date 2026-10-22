<?php

namespace App\Domain\Campaigns\Services;

use App\Models\Campaign;
use LogicException;

/**
 * Transaction-bound authority for the compiler's not-yet-linked Campaign.
 *
 * Constructing another instance grants nothing: the guard accepts only the
 * exact currently issued object and revokes it when the compiler callback
 * exits. Each Campaign is sealed as soon as its complete lineage is linked.
 */
final class GovernedCampaignCompilationCapability
{
    /** @var array<int, true> */
    private array $campaignIds = [];

    /** @var array<int, true> */
    private array $sealedCampaignIds = [];

    private bool $active = true;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $compilationId,
        public readonly int $transactionLevel,
    ) {}

    public function register(Campaign $campaign): void
    {
        $this->assertActive();
        if ((int) $campaign->getAttribute('workspace_id') !== $this->workspaceId
            || ! is_numeric($campaign->getKey())) {
            throw new LogicException('The compiler capability cannot register this Campaign.');
        }

        $this->campaignIds[(int) $campaign->getKey()] = true;
    }

    public function seal(Campaign $campaign): void
    {
        $this->assertActive();
        $campaignId = (int) $campaign->getKey();
        if (! isset($this->campaignIds[$campaignId])) {
            throw new LogicException('The compiler capability does not own this Campaign.');
        }

        $this->sealedCampaignIds[$campaignId] = true;
    }

    public function allows(int $workspaceId, int $campaignId, int $transactionLevel): bool
    {
        return $this->active
            && $workspaceId === $this->workspaceId
            && $transactionLevel >= $this->transactionLevel
            && isset($this->campaignIds[$campaignId])
            && ! isset($this->sealedCampaignIds[$campaignId]);
    }

    public function authorizes(int $workspaceId, int $transactionLevel): bool
    {
        return $this->active
            && $workspaceId === $this->workspaceId
            && $transactionLevel >= $this->transactionLevel;
    }

    public function owns(Campaign $campaign, int $transactionLevel): bool
    {
        return $this->allows(
            (int) $campaign->getAttribute('workspace_id'),
            (int) $campaign->getKey(),
            $transactionLevel,
        );
    }

    public function revoke(): void
    {
        $this->active = false;
        $this->campaignIds = [];
        $this->sealedCampaignIds = [];
    }

    private function assertActive(): void
    {
        if (! $this->active) {
            throw new LogicException('The compiler capability has expired.');
        }
    }
}
