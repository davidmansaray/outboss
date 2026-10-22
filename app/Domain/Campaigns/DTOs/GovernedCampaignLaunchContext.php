<?php

namespace App\Domain\Campaigns\DTOs;

use App\Models\Campaign;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Short-lived, callback-scoped authority for one atomic Objective plan launch.
 * It cannot be constructed or retained by a controller or queue payload.
 */
final class GovernedCampaignLaunchContext
{
    private bool $active = true;

    /** @param list<int> $campaignIds */
    private function __construct(
        private readonly int $workspaceId,
        private readonly string $planId,
        private readonly int $actorId,
        private readonly array $campaignIds,
        private readonly int $transactionLevel,
    ) {}

    /**
     * @template T
     *
     * @param  list<int>  $campaignIds
     * @param  Closure(self):T  $callback
     * @return T
     */
    public static function run(
        int $workspaceId,
        string $planId,
        int $actorId,
        array $campaignIds,
        Closure $callback,
    ): mixed {
        if (DB::transactionLevel() < 1 || $workspaceId < 1 || $planId === '' || $actorId < 1) {
            throw new LogicException('Governed Campaign launch requires an active Objective transaction.');
        }
        $ids = array_values(array_unique(array_map('intval', $campaignIds)));
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            throw new LogicException('Governed Campaign launch requires at least one compiled arm.');
        }
        $context = new self($workspaceId, $planId, $actorId, $ids, DB::transactionLevel());

        try {
            return $callback($context);
        } finally {
            $context->active = false;
        }
    }

    public function authorizes(Campaign $campaign, string $planId): bool
    {
        return $this->active
            && DB::transactionLevel() >= $this->transactionLevel
            && (int) $campaign->getAttribute('workspace_id') === $this->workspaceId
            && hash_equals($this->planId, $planId)
            && in_array((int) $campaign->id, $this->campaignIds, true);
    }

    public function actorId(): int
    {
        return $this->actorId;
    }
}
