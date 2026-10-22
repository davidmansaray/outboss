<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\People\Support\ProspectSourceSurface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns a bounded prospect source run and its counters. Raw rows and provider payloads are not included.')]
#[IsReadOnly]
class GetSourceRun extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['run_id' => $schema->string()->required()->description('Source run UUID.')];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::SourcesRead->value)) {
            return $denied;
        }

        $workspace = $this->actor($request)->currentWorkspace();
        $runId = (string) $request->get('run_id');
        $run = null;
        foreach (ProspectSourceSurface::list($workspace) as $source) {
            $candidate = ProspectSourceSurface::runs($workspace, (string) $source['id'])
                ->first(fn (array $item): bool => $item['id'] === $runId);
            if ($candidate !== null) {
                $run = $candidate;
                break;
            }
        }

        return $run === null ? Response::error('Source run not found.') : $this->json($run);
    }
}
