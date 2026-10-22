<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Gtm\Services\ConfigureOperationalOffer;
use App\Http\Resources\Api\V1\OfferResource;
use App\Models\Offer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Proposes/configures a bounded operational Offer Version for owner review. This creates no approval, activation, fulfilment execution, campaign, or sending side effect.')]
class ConfigureOffer extends OutbossTool
{
    public function __construct(private readonly ConfigureOperationalOffer $configure) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'offer_id' => $schema->string()->required()->description('The Offer UUID.'),
            'payload' => $schema->object()->required()->description('Bounded operational Offer V2 payload.'),
            'expected_lock_version' => $schema->integer()->required()->min(0)->description('Current Offer lock version.'),
            'operation_id' => $schema->string()->required()->max(160)->description('Stable retry/operation key.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::OffersWrite->value)) {
            return $denied;
        }

        try {
            $user = $this->actor($request);
            $offer = $this->workspaceModel($request, Offer::class, (string) $request->get('offer_id'));
            Gate::forUser($user)->authorize('update', $offer);
            $data = $request->validate([
                'offer_id' => ['required', 'uuid'],
                'payload' => ['required', 'array', 'max:30'],
                'expected_lock_version' => ['required', 'integer', 'min:0'],
                'operation_id' => ['required', 'string', 'max:160'],
            ]);
            $updated = $this->configure->handle(
                $offer,
                $user,
                $this->boundedS2Payload($data['payload'], 'payload'),
                (int) $data['expected_lock_version'],
                (string) $data['operation_id'],
            );

            return $this->json((new OfferResource($updated->load(['currentVersion', 'currentReadinessEvaluation'])))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
