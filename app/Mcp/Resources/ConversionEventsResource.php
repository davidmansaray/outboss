<?php

namespace App\Mcp\Resources;

use App\Domain\Api\TokenScope;
use App\Http\Resources\Api\V1\ConversionEventResource;
use App\Mcp\Concerns\ChecksScopes;
use App\Models\ConversionEvent;
use JsonException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('Safe Conversion Event index for the authenticated workspace, capped at 100 records. Receipt envelopes and raw source payloads are omitted.')]
#[Uri('outboss://conversion-events')]
#[MimeType('application/json')]
class ConversionEventsResource extends Resource
{
    use ChecksScopes;

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::ConversionsRead->value)) {
            return $denied;
        }

        $workspace = $this->actor($request)->currentWorkspace();
        $events = ConversionEvent::query()
            ->where('workspace_id', $workspace->id)
            ->with(['matches', 'reviewEvents'])
            ->latest('occurred_at')
            ->limit(100)
            ->get();
        $payload = [
            'events' => ConversionEventResource::collection($events)->resolve(),
            'truncated' => $events->count() === 100,
        ];

        try {
            return Response::text(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException) {
            return Response::error('The resource could not encode its response.');
        }
    }
}
