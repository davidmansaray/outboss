<?php

namespace App\Mcp\Resources;

use App\Domain\Api\TokenScope;
use App\Http\Resources\Api\V1\OfferReadinessResource as ApiOfferReadinessResource;
use App\Mcp\Concerns\ChecksScopes;
use App\Models\Offer;
use JsonException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('Current safe Offer readiness projections for the authenticated workspace, capped at 100 Offers. This resource cannot approve or activate Offers.')]
#[Uri('outboss://offers/readiness')]
#[MimeType('application/json')]
class OfferReadinessResource extends Resource
{
    use ChecksScopes;

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::OffersRead->value)) {
            return $denied;
        }

        $workspace = $this->actor($request)->currentWorkspace();
        $offers = Offer::query()
            ->where('workspace_id', $workspace->id)
            ->with('currentReadinessEvaluation')
            ->latest('updated_at')
            ->limit(100)
            ->get();
        $payload = [
            'offers' => $offers->map(static fn (Offer $offer): array => [
                'id' => (string) $offer->id,
                'status' => $offer->status->value,
                'availability_state' => $offer->availability_state->value,
                'lock_version' => (int) $offer->lock_version,
                'readiness' => $offer->currentReadinessEvaluation === null
                    ? null
                    : (new ApiOfferReadinessResource($offer->currentReadinessEvaluation))->resolve(),
            ])->values()->all(),
            'truncated' => $offers->count() === 100,
        ];

        try {
            return Response::text(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException) {
            return Response::error('The resource could not encode its response.');
        }
    }
}
