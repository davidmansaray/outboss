<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Ai\DTOs\LlmResult;
use App\Domain\Ai\DTOs\ResearchExtractRequest;
use App\Domain\Ai\DTOs\ResearchResult;
use App\Domain\Ai\DTOs\ResearchSearchRequest;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Services\ExecuteProviderOperation;
use App\Domain\Intelligence\Contracts\IntelligenceProviderBridge;
use App\Models\User;
use App\Models\Workspace;

/** AF-01 adapter facade; no Intelligence code calls provider adapters directly. */
final class Af01IntelligenceProviderBridge implements IntelligenceProviderBridge
{
    public function __construct(private readonly ExecuteProviderOperation $executor) {}

    public function extract(Workspace $workspace, User $actor, ResearchExtractRequest $request, string $idempotencyKey, ?string $budgetRunId = null): ResearchResult
    {
        return $this->executor->extract($workspace, $actor, $request, $idempotencyKey, $budgetRunId);
    }

    public function search(Workspace $workspace, User $actor, ResearchSearchRequest $request, string $idempotencyKey, ?string $budgetRunId = null): ResearchResult
    {
        return $this->executor->search($workspace, $actor, $request, $idempotencyKey, $budgetRunId);
    }

    public function generate(Workspace $workspace, User $actor, string $content, string $idempotencyKey, ?string $budgetRunId = null, AiTask $task = AiTask::AccountResearch): LlmResult
    {
        return $this->executor->generate($workspace, $actor, $task, $content, $idempotencyKey, $budgetRunId);
    }
}
