<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Http\Resources\Api\V1\AccountResearchRunResource;
use App\Models\Account;
use App\Models\AccountResearchRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns one workspace-scoped Account research run with bounded stages, attempts, evidence metadata, claims, and qualification decisions. Provider output and checkpoint content are omitted.')]
#[IsReadOnly]
class GetAccountResearchRun extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->required()->description('The research run UUID.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::IntelligenceRead->value)) {
            return $denied;
        }

        try {
            $run = $this->workspaceModel($request, AccountResearchRun::class, (string) $request->get('run_id'));
            $workspace = $this->actor($request)->currentWorkspace();
            $account = Account::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($run->account_id)
                ->first();
            if ($account === null) {
                throw new AuthorizationException('This action is unauthorized.');
            }
            Gate::forUser($this->actor($request))->authorize('view', $account);
            $run->load([
                'stages.attempts',
                'attempts',
                'snapshots.evidence',
                'claims.evidence',
                'qualificationDecisions.claimLinks',
                'qualificationDecisions.reasonsToContact',
                'qualificationDecisions.reviewEvents',
            ]);

            return $this->json((new AccountResearchRunResource($run))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
