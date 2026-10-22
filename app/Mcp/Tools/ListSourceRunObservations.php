<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\People\Support\ProspectSourceSurface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists policy-filtered observations for one source run. Values are limited to disposition, match, bounded error, and canonical ids; raw input and provider bodies are omitted.')]
#[IsReadOnly]
class ListSourceRunObservations extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->required()->description('Source run UUID.'),
            ...$this->paginationSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::SourcesRead->value)) {
            return $denied;
        }

        $workspace = $this->actor($request)->currentWorkspace();
        $payload = ProspectSourceSurface::observations(
            $workspace,
            (string) $request->get('run_id'),
            $this->page($request),
            $this->perPage($request),
        );

        return $this->json($payload);
    }
}
