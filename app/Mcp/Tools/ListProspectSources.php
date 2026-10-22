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

#[Description('Lists prospect sources in the authenticated workspace, including the latest run state and bounded yield counts. Defaults to 20 results (maximum 100).')]
#[IsReadOnly]
class ListProspectSources extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Optional search across source name, kind, and status.'),
            ...$this->paginationSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::SourcesRead->value)) {
            return $denied;
        }

        $workspace = $this->actor($request)->currentWorkspace();
        $query = mb_strtolower(trim((string) $request->get('query', '')));
        $sources = collect(ProspectSourceSurface::list($workspace));

        if ($query !== '') {
            $sources = $sources->filter(fn (array $source): bool => str_contains(mb_strtolower(implode(' ', [
                (string) $source['display_name'],
                (string) $source['kind'],
                (string) $source['status'],
            ])), $query));
        }

        $perPage = $this->perPage($request);
        $page = $this->page($request);
        $total = $sources->count();

        return $this->json($this->pagePayload(
            ProspectSourceResource::collection($sources->forPage($page, $perPage)->values())->resolve(),
            $page,
            $perPage,
            $total,
        ));
    }
}
