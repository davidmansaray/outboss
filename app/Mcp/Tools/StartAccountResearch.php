<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Intelligence\DTOs\StartAccountResearchRequest;
use App\Domain\Intelligence\Services\StartAccountResearch as StartService;
use App\Http\Resources\Api\V1\AccountResearchRunResource;
use App\Models\Account;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Starts one bounded, idempotent Account research run after revalidating its preflight. This queues research only; it does not enrol, contact, or send to anyone.')]
class StartAccountResearch extends OutbossTool
{
    public function __construct(private readonly StartService $start) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->integer()->required()->min(1)->description('The Account id in the authenticated workspace.'),
            'idempotency_key' => $schema->string()->required()->max(160)->description('Stable retry key.'),
            'preflight_hash' => $schema->string()->max(64)->description('Optional hash returned by preflight.'),
            'gtm_profile_version_id' => $schema->string()->description('Optional workspace GTM Profile Version UUID.'),
            'offer_version_id' => $schema->string()->description('Optional workspace Offer Version UUID.'),
            'freshness_policy' => $schema->object()->description('Optional bounded freshness policy.'),
            'capabilities' => $schema->array()->max(3)->description('Optional selected research capabilities.'),
            'maximum_cost_micros' => $schema->integer()->min(1)->description('Optional hard maximum cost in micros.'),
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
            $account = Account::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($request->integer('account_id'))
                ->first();
            if ($account === null) {
                throw new AuthorizationException('This action is unauthorized.');
            }
            Gate::forUser($user)->authorize('view', $account);
            $data = $request->validate([
                'account_id' => ['required', 'integer', 'min:1'],
                'idempotency_key' => ['required', 'string', 'max:160'],
                'preflight_hash' => ['sometimes', 'nullable', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
                'gtm_profile_version_id' => ['sometimes', 'nullable', 'uuid'],
                'offer_version_id' => ['sometimes', 'nullable', 'uuid'],
                'freshness_policy' => ['sometimes', 'array', 'max:10'],
                'freshness_policy.snapshot_max_age_hours' => ['sometimes', 'integer', 'min:1', 'max:8760'],
                'freshness_policy.claim_max_age_hours' => ['sometimes', 'integer', 'min:1', 'max:8760'],
                'capabilities' => ['sometimes', 'array', 'max:3'],
                'capabilities.*' => ['string', 'max:64'],
                'maximum_cost_micros' => ['sometimes', 'integer', 'min:1', 'max:9223372036854775807'],
            ]);
            $run = $this->start->start(new StartAccountResearchRequest(
                workspace: $workspace,
                account: $account,
                actor: $user,
                idempotencyKey: $data['idempotency_key'],
                gtmProfileVersionId: $data['gtm_profile_version_id'] ?? null,
                offerVersionId: $data['offer_version_id'] ?? null,
                freshnessPolicy: is_array($data['freshness_policy'] ?? null) ? $data['freshness_policy'] : [],
                capabilities: $this->stringList($data['capabilities'] ?? null, 'capabilities'),
                maximumCostMicros: isset($data['maximum_cost_micros']) ? (int) $data['maximum_cost_micros'] : 1_500_000,
                preflightHash: $data['preflight_hash'] ?? null,
            ));

            return $this->json([
                'run' => (new AccountResearchRunResource($run->load('stages')))->resolve(),
                'accepted' => true,
            ]);
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
