<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Http\Resources\Api\V1\OfferResource;
use App\Models\Offer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns one workspace-scoped Offer including safe current-version payload, readiness history, and review metadata. MCP cannot approve or activate an Offer.')]
#[IsReadOnly]
class GetOffer extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'offer_id' => $schema->string()->required()->description('The Offer UUID.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::OffersRead->value)) {
            return $denied;
        }

        try {
            $offer = $this->workspaceModel($request, Offer::class, (string) $request->get('offer_id'));
            Gate::forUser($this->actor($request))->authorize('view', $offer);
            $offer->load(['currentVersion', 'currentReadinessEvaluation', 'readinessEvaluations', 'versionReviewEvents']);

            return $this->json((new OfferResource($offer))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
