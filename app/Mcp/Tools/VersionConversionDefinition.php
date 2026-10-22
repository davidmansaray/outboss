<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Conversions\Services\VersionConversionDefinition as VersionService;
use App\Http\Resources\Api\V1\ConversionDefinitionResource;
use App\Models\ConversionDefinition;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Appends a bounded immutable Conversion Definition Version in the authenticated workspace. Requires the current lock version and idempotency key.')]
class VersionConversionDefinition extends OutbossTool
{
    public function __construct(private readonly VersionService $version) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'definition_id' => $schema->string()->required()->description('The Conversion Definition UUID.'),
            'expected_lock_version' => $schema->integer()->required()->min(0)->description('Current definition lock version.'),
            'idempotency_key' => $schema->string()->required()->max(160)->description('Stable retry key.'),
            'changes' => $schema->object()->required()->description('Bounded fields to replace on the current definition version.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::ConversionsWrite->value)) {
            return $denied;
        }

        try {
            $user = $this->actor($request);
            $definition = $this->workspaceModel($request, ConversionDefinition::class, (string) $request->get('definition_id'));
            $data = $request->validate([
                'definition_id' => ['required', 'uuid'],
                'expected_lock_version' => ['required', 'integer', 'min:0'],
                'idempotency_key' => ['required', 'string', 'max:160'],
                'changes' => ['required', 'array', 'max:20'],
            ]);
            $updated = $this->version->handle(
                $definition,
                $user,
                $this->boundedS2Payload($data['changes'], 'changes'),
                (int) $data['expected_lock_version'],
                (string) $data['idempotency_key'],
            );

            return $this->json((new ConversionDefinitionResource($updated->load('currentVersion')))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
