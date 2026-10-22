<?php

namespace App\Mcp\Resources;

use App\Domain\Api\TokenScope;
use App\Http\Resources\Api\V1\AssetResource as ApiAssetResource;
use App\Mcp\Concerns\ChecksScopes;
use App\Models\Asset;
use JsonException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('Safe current Asset metadata for the authenticated workspace. Restricted fields, delivery/access instructions, tracking metadata, and raw content are omitted.')]
#[Uri('outboss://assets')]
#[MimeType('application/json')]
class AssetsResource extends Resource
{
    use ChecksScopes;

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::OffersRead->value)) {
            return $denied;
        }

        try {
            $assets = Asset::query()
                ->where('workspace_id', $this->actor($request)->currentWorkspace()->id)
                ->with('currentVersion')
                ->latest('updated_at')
                ->limit(100)
                ->get();

            return Response::text(json_encode([
                'assets' => ApiAssetResource::collection($assets)->resolve(),
                'truncated' => $assets->count() === 100,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException) {
            return Response::error('The resource could not encode its response.');
        }
    }
}
