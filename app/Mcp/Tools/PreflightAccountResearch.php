<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Intelligence\Services\PreflightAccountResearch as PreflightService;
use App\Http\Resources\Api\V1\AccountResearchPreflightResource;
use App\Models\Account;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Builds a side-effect-free, bounded Account research preflight. It does not call providers, reserve budget, or enqueue a run.')]
#[IsReadOnly]
class PreflightAccountResearch extends OutbossTool
{
    public function __construct(private readonly PreflightService $preflight) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->integer()->required()->min(1)->description('The Account id in the authenticated workspace.'),
            'gtm_profile_version_id' => $schema->string()->description('Optional workspace GTM Profile Version UUID.'),
            'offer_version_id' => $schema->string()->description('Optional workspace Offer Version UUID.'),
            'freshness_policy' => $schema->object()->description('Optional bounded freshness policy.'),
            'capabilities' => $schema->array()->max(3)->description('Optional selected research capabilities.'),
            'maximum_cost_micros' => $schema->integer()->min(1)->description('Optional hard maximum cost in micros.'),
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
            $data = $request->validate([
                'account_id' => ['required', 'integer', 'min:1'],
                'gtm_profile_version_id' => ['sometimes', 'nullable', 'uuid'],
                'offer_version_id' => ['sometimes', 'nullable', 'uuid'],
                'freshness_policy' => ['sometimes', 'array', 'max:10'],
                'freshness_policy.snapshot_max_age_hours' => ['sometimes', 'integer', 'min:1', 'max:8760'],
                'freshness_policy.claim_max_age_hours' => ['sometimes', 'integer', 'min:1', 'max:8760'],
                'capabilities' => ['sometimes', 'array', 'max:3'],
                'capabilities.*' => ['string', 'max:64'],
                'maximum_cost_micros' => ['sometimes', 'integer', 'min:1', 'max:9223372036854775807'],
            ]);
            $preview = $this->preflight->handle(
                $workspace,
                $account,
                $data['gtm_profile_version_id'] ?? null,
                $data['offer_version_id'] ?? null,
                is_array($data['freshness_policy'] ?? null) ? $data['freshness_policy'] : [],
                $this->stringList($data['capabilities'] ?? null, 'capabilities'),
                isset($data['maximum_cost_micros']) ? (int) $data['maximum_cost_micros'] : null,
            );

            return $this->json((new AccountResearchPreflightResource($preview))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
