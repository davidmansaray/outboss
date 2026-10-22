<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Http\Resources\Api\V1\ConversionEventResource;
use App\Models\ConversionEvent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns one safe workspace-scoped Conversion Event projection with matching/review metadata. Receipt envelopes and raw source content are never returned.')]
#[IsReadOnly]
class GetConversionEvent extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->string()->required()->description('The Conversion Event UUID.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::ConversionsRead->value)) {
            return $denied;
        }

        try {
            $event = $this->workspaceModel($request, ConversionEvent::class, (string) $request->get('event_id'));
            $event->load(['matches', 'reviewEvents']);

            return $this->json((new ConversionEventResource($event))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
