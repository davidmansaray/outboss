<?php

namespace App\Mcp\Resources;

use App\Domain\Api\TokenScope;
use App\Domain\People\Support\ProspectSourceSurface;
use App\Mcp\Concerns\ChecksScopes;
use JsonException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('Safe overview of prospect sources, latest run states, yield counts, and retention warnings for the authenticated workspace. Raw observations and credentials are omitted.')]
#[Uri('outboss://prospect-sources')]
#[MimeType('application/json')]
class ProspectSourcesResource extends Resource
{
    use ChecksScopes;

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::SourcesRead->value)) {
            return $denied;
        }

        try {
            return Response::text(json_encode([
                'sources' => ProspectSourceSurface::list($this->actor($request)->currentWorkspace()),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException) {
            return Response::error('The resource could not encode its response.');
        }
    }
}
