<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Intelligence\Services\ProjectAccountIntelligence;
use App\Http\Resources\Api\V1\AccountIntelligenceResource;
use App\Http\Resources\Api\V1\AccountResearchRunResource;
use App\Http\Resources\Api\V1\QualificationDecisionResource;
use App\Models\Account;
use App\Models\AccountResearchRun;
use App\Models\QualificationDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns one Account intelligence projection with current evidence-backed claims, recent research runs, and the latest qualification decision. Notes and raw source content are omitted.')]
#[IsReadOnly]
class GetAccountIntelligence extends OutbossTool
{
    public function __construct(private readonly ProjectAccountIntelligence $projection) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->integer()->required()->min(1)->description('The Account id in the authenticated workspace.'),
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
            $account = Account::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($request->integer('account_id'))
                ->first();
            if ($account === null) {
                throw new AuthorizationException('This action is unauthorized.');
            }
            Gate::forUser($user)->authorize('view', $account);

            $runs = AccountResearchRun::query()
                ->where('workspace_id', $workspace->id)
                ->where('account_id', $account->id)
                ->with(['stages', 'qualificationDecisions'])
                ->latest('created_at')
                ->limit(20)
                ->get();
            $latestQualification = QualificationDecision::query()
                ->where('workspace_id', $workspace->id)
                ->where('account_id', $account->id)
                ->with(['claimLinks', 'reasonsToContact', 'reviewEvents'])
                ->latest('generated_at')
                ->first();

            return $this->json((new AccountIntelligenceResource([
                'account' => [
                    'id' => (int) $account->id,
                    'name' => mb_substr((string) $account->name, 0, 240),
                    'website' => $account->website,
                    'domain' => $account->domain_normalized,
                    'tags' => array_slice($account->tags, 0, 50),
                    'source' => $account->source,
                    'created_at' => $account->created_at?->toIso8601String(),
                    'updated_at' => $account->updated_at?->toIso8601String(),
                ],
                'intelligence' => $this->projection->handle($workspace, $account),
                'latest_run' => $runs->first() === null ? null : (new AccountResearchRunResource($runs->first()))->resolve(),
                'runs' => AccountResearchRunResource::collection($runs)->resolve(),
                'latest_qualification' => $latestQualification === null
                    ? null
                    : (new QualificationDecisionResource($latestQualification))->resolve(),
            ]))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
