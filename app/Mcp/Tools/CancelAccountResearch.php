<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Intelligence\Services\CancelAccountResearch as CancelService;
use App\Http\Resources\Api\V1\AccountResearchRunResource;
use App\Models\Account;
use App\Models\AccountResearchRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Cancels one workspace-scoped Account research run. It cannot cancel or alter campaign, messaging, or sending work.')]
class CancelAccountResearch extends OutbossTool
{
    public function __construct(private readonly CancelService $cancel) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->required()->description('The research run UUID.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::IntelligenceWrite->value)) {
            return $denied;
        }

        try {
            $user = $this->actor($request);
            $workspace = $user->currentWorkspace();
            $run = $this->workspaceModel($request, AccountResearchRun::class, (string) $request->get('run_id'));
            $account = Account::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($run->account_id)
                ->first();
            if ($account === null) {
                throw new AuthorizationException('This action is unauthorized.');
            }
            Gate::forUser($user)->authorize('view', $account);
            $updated = $this->cancel->handle($workspace, $run, $user);

            return $this->json((new AccountResearchRunResource($updated->load('stages')))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
