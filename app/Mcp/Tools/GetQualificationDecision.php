<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Http\Resources\Api\V1\QualificationDecisionResource;
use App\Models\Account;
use App\Models\QualificationDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns one Account qualification decision with bounded outcome, confidence, claim links, and review history. Internal provider rationale and raw evidence are not returned.')]
#[IsReadOnly]
class GetQualificationDecision extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'decision_id' => $schema->string()->required()->description('The qualification decision UUID.'),
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
            $decision = QualificationDecision::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey((string) $request->get('decision_id'))
                ->with(['claimLinks', 'reasonsToContact', 'reviewEvents'])
                ->first();
            if ($decision === null) {
                throw new AuthorizationException('This action is unauthorized.');
            }
            $account = Account::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($decision->account_id)
                ->first();
            if ($account === null) {
                throw new AuthorizationException('This action is unauthorized.');
            }
            Gate::forUser($user)->authorize('view', $account);

            return $this->json((new QualificationDecisionResource($decision))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
