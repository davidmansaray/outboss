<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Http\Resources\Api\V1\ResearchClaimResource;
use App\Models\Account;
use App\Models\ResearchClaim;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns one evidence-backed research Claim. Evidence includes source and policy metadata only; raw source content, provider payloads, prompts, and secrets are omitted.')]
#[IsReadOnly]
class GetResearchClaim extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'claim_id' => $schema->string()->required()->description('The research Claim UUID.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::IntelligenceRead->value)) {
            return $denied;
        }

        try {
            $user = $this->actor($request);
            $workspace = $user->currentWorkspace();
            $claim = ResearchClaim::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey((string) $request->get('claim_id'))
                ->with('evidence')
                ->first();
            if ($claim === null) {
                throw new AuthorizationException('This action is unauthorized.');
            }
            $account = Account::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($claim->account_id)
                ->first();
            if ($account === null) {
                throw new AuthorizationException('This action is unauthorized.');
            }
            Gate::forUser($user)->authorize('view', $account);

            return $this->json((new ResearchClaimResource($claim))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
