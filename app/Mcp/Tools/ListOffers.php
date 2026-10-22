<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Http\Resources\Api\V1\OfferResource;
use App\Models\Offer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists operational Offers in the authenticated workspace with safe current-version and readiness projections. Approval and activation remain owner-only REST operations and are not available as MCP tools.')]
#[IsReadOnly]
class ListOffers extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Optional lifecycle status filter.'),
            ...$this->paginationSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::OffersRead->value)) {
            return $denied;
        }

        try {
            $workspace = $this->actor($request)->currentWorkspace();
            $offers = Offer::query()
                ->where('workspace_id', $workspace->id)
                ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->get('status')))
                ->with(['currentVersion', 'currentReadinessEvaluation'])
                ->latest('updated_at')
                ->paginate($this->perPage($request), ['*'], 'page', $this->page($request));

            return $this->json($this->pagePayload(
                OfferResource::collection($offers->getCollection())->resolve(),
                $offers->currentPage(),
                $offers->perPage(),
                $offers->total(),
            ));
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
