<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Gtm\Services\EvaluateOfferReadiness;
use App\Http\Resources\Api\V1\OfferReadinessResource;
use App\Models\Offer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Evaluates and returns deterministic readiness for one Offer in the authenticated workspace. It performs no provider call and does not approve or activate the Offer.')]
#[IsReadOnly]
class GetOfferReadiness extends OutbossTool
{
    public function __construct(private readonly EvaluateOfferReadiness $readiness) {}

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
            $evaluation = $this->readiness->handle($offer, 'mcp_read');

            return $this->json((new OfferReadinessResource($evaluation))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
