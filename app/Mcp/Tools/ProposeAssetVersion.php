<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Gtm\Services\VersionAsset as VersionService;
use App\Http\Resources\Api\V1\AssetResource;
use App\Models\Asset;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Proposes a bounded draft Asset Version for owner review. It never approves, activates, fulfils, sends, or exposes restricted/raw Asset data. The current Asset lock version and a stable operation key are required.')]
class ProposeAssetVersion extends OutbossTool
{
    public function __construct(private readonly VersionService $version) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'asset_id' => $schema->string()->required()->description('The Asset UUID.'),
            'changes' => $schema->object()->required()->description('Bounded external Asset metadata changes.'),
            'expected_lock_version' => $schema->integer()->required()->min(0)->description('Current Asset lock version.'),
            'operation_id' => $schema->string()->required()->max(160)->description('Stable retry key.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::OffersWrite->value)) {
            return $denied;
        }

        try {
            $user = $this->actor($request);
            $workspace = $user->currentWorkspace();
            $asset = Asset::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey((string) $request->get('asset_id'))
                ->with('currentVersion')
                ->first();
            if ($asset === null) {
                throw new AuthorizationException('This action is unauthorized.');
            }
            Gate::forUser($user)->authorize('update', $asset);
            $data = $request->validate([
                'asset_id' => ['required', 'uuid'],
                'changes' => ['required', 'array', 'max:20'],
                'expected_lock_version' => ['required', 'integer', 'min:0'],
                'operation_id' => ['required', 'string', 'max:160'],
            ]);
            $changes = is_array($data['changes']) ? $data['changes'] : [];
            if (($changes['approval_state'] ?? null) === 'approved') {
                throw ValidationException::withMessages([
                    'changes.approval_state' => 'MCP Asset proposals must remain draft until an owner approves them.',
                ]);
            }
            $changes['approval_state'] = 'draft';
            $updated = $this->version->handle(
                $asset,
                $user,
                $this->boundedS2Payload($changes, 'changes'),
                (int) $data['expected_lock_version'],
                (string) $data['operation_id'],
            );

            return $this->json([
                'proposed' => true,
                'asset' => (new AssetResource($updated->load('currentVersion')))->resolve(),
            ]);
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
