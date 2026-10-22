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

#[Description('Lists versioned Conversion Definitions in the authenticated workspace. Secrets and connection credentials are never returned.')]
#[IsReadOnly]
class ListConversionDefinitions extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Optional status filter: draft, active, or archived.'),
            ...$this->paginationSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::ConversionsRead->value)) {
            return $denied;
        }

        try {
            $workspace = $this->actor($request)->currentWorkspace();
            $definitions = ConversionDefinition::query()
                ->where('workspace_id', $workspace->id)
                ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->get('status')))
                ->with('currentVersion')
                ->orderBy('event_key')
                ->paginate($this->perPage($request), ['*'], 'page', $this->page($request));

            return $this->json($this->pagePayload(
                ConversionDefinitionResource::collection($definitions->getCollection())->resolve(),
                $definitions->currentPage(),
                $definitions->perPage(),
                $definitions->total(),
            ));
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
