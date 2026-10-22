<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Http\Resources\Api\V1\SuppressionEntryResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists suppression entries for the authenticated workspace. Suppressed addresses will not be enrolled or emailed. Defaults to 20 results (maximum 100). Does not mutate data.')]
#[IsReadOnly]
class ListSuppressions extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return $this->paginationSchema($schema);
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::SuppressionsRead->value)) {
            return $denied;
        }

        $entries = $this->actor($request)->currentWorkspace()
            ->suppressions()
            ->latest()
            ->paginate($this->perPage($request), ['*'], 'page', $this->page($request));

        return $this->json($this->pagePayload(
            SuppressionEntryResource::collection($entries->getCollection())->resolve(),
            $entries->currentPage(),
            $entries->perPage(),
            $entries->total(),
        ));
    }
}
