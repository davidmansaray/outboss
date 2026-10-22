<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Intelligence\Services\CorrectResearchClaim as CorrectService;
use App\Http\Resources\Api\V1\ResearchClaimResource;
use App\Models\Account;
use App\Models\ResearchClaim;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Appends an owner correction successor for one research Claim. The original claim and evidence remain immutable; correction values are bounded and never expose source text.')]
class CorrectResearchClaim extends OutbossTool
{
    public function __construct(private readonly CorrectService $correct) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'claim_id' => $schema->string()->required()->description('The research Claim UUID.'),
            'value' => $schema->anyOf([
                $schema->string(),
                $schema->number(),
                $schema->integer(),
                $schema->boolean(),
                $schema->array(),
                $schema->object(),
            ])->required()->description('Bounded replacement value.'),
            'value_type' => $schema->string()->description('Optional type: string, url, date, integer, number, boolean, string_list, or object.'),
            'reason' => $schema->string()->max(2000)->description('Optional bounded correction reason.'),
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
            $claim = ResearchClaim::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey((string) $request->get('claim_id'))
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
            Gate::forUser($user)->authorize('update', $account);
            $data = $request->validate([
                'claim_id' => ['required', 'uuid'],
                'value' => ['required'],
                'value_type' => ['sometimes', 'string', 'in:string,url,date,integer,number,boolean,string_list,object'],
                'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ]);
            $encoded = json_encode($data['value'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($encoded) > 16_384) {
                throw ValidationException::withMessages(['value' => 'The correction value exceeds the bounded size limit.']);
            }
            $successor = $this->correct->handle(
                $workspace,
                $claim,
                $user,
                $data['value'],
                $data['reason'] ?? null,
                $data['value_type'] ?? null,
            );

            return $this->json((new ResearchClaimResource($successor->load('evidence')))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
