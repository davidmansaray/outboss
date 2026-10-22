<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Http\Resources\Api\V1\ConversionDefinitionResource;
use App\Models\ConversionDefinition;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns one workspace-scoped Conversion Definition and its immutable versions without source secrets or raw event payloads.')]
#[IsReadOnly]
class GetConversionDefinition extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'definition_id' => $schema->string()->required()->description('The Conversion Definition UUID.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::ConversionsRead->value)) {
            return $denied;
        }

        try {
            $definition = $this->workspaceModel($request, ConversionDefinition::class, (string) $request->get('definition_id'));
            $definition->load(['currentVersion', 'versions']);

            return $this->json((new ConversionDefinitionResource($definition))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
