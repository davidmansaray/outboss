<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\DTOs\StartAccountResearchRequest;
use App\Domain\Intelligence\Support\ModelAttribute;
use App\Models\AccountResearchRun;
use App\Models\User;
use App\Models\Workspace;

/** Start an explicit child run; refresh never mutates/deletes parent evidence. */
final class RefreshAccountResearch
{
    public function __construct(private readonly StartAccountResearch $start) {}

    public function handle(
        Workspace $workspace,
        AccountResearchRun $parent,
        User $actor,
        string $idempotencyKey,
        ?string $preflightHash = null,
        ?int $maximumCostMicros = null,
    ): AccountResearchRun {
        if ((int) $parent->workspace_id !== (int) $workspace->id) {
            throw new \RuntimeException('The parent run does not belong to this workspace.');
        }

        return $this->start->start(new StartAccountResearchRequest(
            workspace: $workspace,
            account: (int) $parent->account_id,
            actor: $actor,
            idempotencyKey: $idempotencyKey,
            gtmProfileVersionId: $parent->gtm_profile_version_id,
            offerVersionId: $parent->offer_version_id,
            freshnessPolicy: ModelAttribute::array($parent->freshness_policy),
            capabilities: $this->selectedCapabilities($parent),
            maximumCostMicros: $maximumCostMicros ?? (int) $parent->maximum_cost_micros,
            preflightHash: $preflightHash,
            parentRunId: (string) $parent->id,
        ));
    }

    /** @return list<string> */
    private function selectedCapabilities(AccountResearchRun $parent): array
    {
        $selected = ModelAttribute::array($parent->capability_snapshot)['selected'] ?? [];
        if (! is_array($selected)) {
            return [];
        }

        $capabilities = [];
        foreach ($selected as $capability) {
            if (is_string($capability)) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }
}
