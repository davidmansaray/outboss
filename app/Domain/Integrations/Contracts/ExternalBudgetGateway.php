<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\DTOs\ExternalCostEstimate;
use App\Models\ExternalOperation;
use App\Models\IntegrationProviderRegistration;
use App\Models\User;
use App\Models\Workspace;

interface ExternalBudgetGateway
{
    public function mayAdmit(Workspace $workspace, int $costMicros): bool;

    public function reserve(
        Workspace $workspace,
        ?User $initiator,
        string $provider,
        string $capability,
        string $operation,
        string $endpoint,
        ExternalCostEstimate $estimate,
        string $idempotencyKey,
        string $requestFingerprint,
        ?string $sourceRunId = null,
        ?IntegrationProviderRegistration $resolvedRegistration = null,
    ): ExternalOperation;

    /** @param array<string, mixed> $usage */
    public function settle(
        ExternalOperation $operation,
        ExternalCostEstimate $actual,
        array $usage = [],
        string $status = 'succeeded',
    ): ExternalOperation;

    public function releaseDefinitelyNotSent(
        ExternalOperation $operation,
        string $safeCode,
        string $safeMessage,
    ): ExternalOperation;

    public function markAmbiguous(
        ExternalOperation $operation,
        string $safeCode,
        string $safeMessage,
    ): ExternalOperation;
}
