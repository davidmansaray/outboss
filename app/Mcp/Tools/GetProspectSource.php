<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\People\Support\ProspectSourceSurface;
use App\Http\Resources\Api\V1\ProspectSourceResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns one prospect source, its bounded policy summary, versions, and run history. Does not expose raw input rows or credentials.')]
#[IsReadOnly]
class GetProspectSource extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'source_id' => $schema->string()->required()->description('Prospect source UUID.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::SourcesRead->value)) {
            return $denied;
        }

        $source = ProspectSourceSurface::detail(
            $this->actor($request)->currentWorkspace(),
            (string) $request->get('source_id'),
        );

        if ($source === null) {
            return Response::error('Prospect source not found.');
        }

        return $this->json((new ProspectSourceResource($source))->resolve());
    }
}
