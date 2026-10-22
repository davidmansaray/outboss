<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Http\Resources\Api\V1\AssetResource;
use App\Models\Asset;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists safe, workspace-scoped external Assets and current immutable versions. Restricted fields, access instructions, tracking metadata, and raw content are omitted.')]
#[IsReadOnly]
class ListAssets extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Optional Asset lifecycle status filter: active or archived.'),
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
            $assets = Asset::query()
                ->where('workspace_id', $workspace->id)
                ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->get('status')))
                ->with('currentVersion')
                ->latest('updated_at')
                ->paginate($this->perPage($request), ['*'], 'page', $this->page($request));

            return $this->json($this->pagePayload(
                AssetResource::collection($assets->getCollection())->resolve(),
                $assets->currentPage(),
                $assets->perPage(),
                $assets->total(),
            ));
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
