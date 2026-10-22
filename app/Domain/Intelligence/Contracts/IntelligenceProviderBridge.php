<?php

namespace App\Domain\Intelligence\Contracts;

use App\Domain\Ai\DTOs\LlmResult;
use App\Domain\Ai\DTOs\ResearchExtractRequest;
use App\Domain\Ai\DTOs\ResearchResult;
use App\Domain\Ai\DTOs\ResearchSearchRequest;
use App\Domain\Ai\Enums\AiTask;
use App\Models\User;
use App\Models\Workspace;

/**
 * Intelligence's only provider boundary. Implementations may use AF-01 or a
 * deterministic test adapter without leaking provider details into the
 * research aggregate.
 */
interface IntelligenceProviderBridge
{
    public function extract(Workspace $workspace, User $actor, ResearchExtractRequest $request, string $idempotencyKey, ?string $budgetRunId = null): ResearchResult;

    public function search(Workspace $workspace, User $actor, ResearchSearchRequest $request, string $idempotencyKey, ?string $budgetRunId = null): ResearchResult;

    public function generate(Workspace $workspace, User $actor, string $content, string $idempotencyKey, ?string $budgetRunId = null, AiTask $task = AiTask::AccountResearch): LlmResult;
}
