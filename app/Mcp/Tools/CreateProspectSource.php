<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\People\Support\ProspectSourceSurface;
use App\Domain\Prospecting\Services\CreateProspectSource as CreateProspectSourceService;
use App\Http\Resources\Api\V1\ProspectSourceResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Creates a named, policy-bound prospect source in the authenticated workspace. Owner access is required; this cannot configure credentials or enable unavailable providers.')]
class CreateProspectSource extends OutbossTool
{
    public function __construct(private CreateProspectSourceService $create) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'display_name' => $schema->string()->required()->description('Bounded source name.'),
            'kind' => $schema->string()->required()->description('Source kind, such as csv_import, manual, or existing_accounts.'),
            'configuration' => $schema->object()->description('Optional bounded source configuration.'),
            'policy' => $schema->object()->description('Optional bounded source policy snapshot.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::SourcesWrite->value)) {
            return $denied;
        }

        $user = $this->actor($request);
        $workspace = $user->currentWorkspace();
        if (! $workspace->isOwner($user)) {
            return Response::error('Only workspace owners can manage prospect sources.');
        }

        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'string', 'max:60'],
            'configuration' => ['sometimes', 'array', 'max:20'],
            'policy' => ['sometimes', 'array', 'max:30'],
        ]);
        $source = $this->create->handle(
            workspace: $workspace,
            kind: $data['kind'],
            displayName: $data['display_name'],
            configuration: $data['configuration'] ?? [],
            policy: $data['policy'] ?? [],
            actor: $user,
            origin: 'mcp',
        );
        $source = ProspectSourceSurface::detail($workspace, (string) $source->getKey()) ?? $source;

        return $this->json((new ProspectSourceResource($source))->resolve());
    }
}
