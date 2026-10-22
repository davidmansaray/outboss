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

#[Description('Returns one safe Asset and its immutable version history from the authenticated workspace. Restricted/raw metadata and delivery instructions are never returned.')]
#[IsReadOnly]
class GetAsset extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'asset_id' => $schema->string()->required()->description('The Asset UUID.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::OffersRead->value)) {
            return $denied;
        }

        try {
            $asset = Asset::query()
                ->where('workspace_id', $this->actor($request)->currentWorkspace()->id)
                ->whereKey((string) $request->get('asset_id'))
                ->with(['currentVersion', 'versions'])
                ->first();
            if ($asset === null) {
                return Response::error('Asset not found.');
            }

            return $this->json((new AssetResource($asset))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
