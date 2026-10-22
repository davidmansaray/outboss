<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Conversions\DTOs\ConversionIntakeResult;
use App\Domain\Conversions\Services\IngestConversionEvent as IngestService;
use App\Http\Resources\Api\V1\ConversionEventResource;
use App\Models\ConversionDefinition;
use App\Models\ConversionDefinitionVersion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Records one bounded manual or authenticated API Conversion Event through the shared intake/matching pipeline. Identities are stored as safe handles; raw unmatched identity values and receipt envelopes are not returned.')]
class CreateConversionEvent extends OutbossTool
{
    public function __construct(private readonly IngestService $ingest) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'definition_id' => $schema->string()->description('Workspace Conversion Definition UUID.'),
            'definition_version_id' => $schema->string()->description('Optional exact workspace Definition Version UUID.'),
            'source' => $schema->string()->description('manual or api; defaults to manual.'),
            'idempotency_key' => $schema->string()->required()->max(160)->description('Stable retry key.'),
            'external_event_id' => $schema->string()->max(255)->description('Optional source event id.'),
            'occurred_at' => $schema->string()->description('Optional ISO-8601 occurrence timestamp.'),
            'properties' => $schema->object()->description('Allowlisted Conversion Definition properties.'),
            'identities' => $schema->array()->max(10)->description('Exact Account identity objects.'),
            'account_id' => $schema->integer()->min(1)->description('Optional explicit Account identity.'),
            'domain' => $schema->string()->max(253)->description('Optional public Account domain identity.'),
            'external_identity' => $schema->object()->description('Optional external Account identity object.'),
            'value' => $schema->number()->description('Optional numeric event value.'),
            'currency' => $schema->string()->max(3)->description('Optional ISO-4217 currency.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::ConversionsWrite->value)) {
            return $denied;
        }

        try {
            $user = $this->actor($request);
            $workspace = $user->currentWorkspace();
            $data = $request->validate([
                'definition_id' => ['sometimes', 'nullable', 'uuid'],
                'definition_version_id' => ['sometimes', 'nullable', 'uuid'],
                'source' => ['sometimes', 'string', 'in:manual,api'],
                'idempotency_key' => ['required', 'string', 'max:160'],
                'external_event_id' => ['sometimes', 'nullable', 'string', 'max:255'],
                'occurred_at' => ['sometimes', 'nullable', 'date'],
                'properties' => ['sometimes', 'array', 'max:100'],
                'identities' => ['sometimes', 'array', 'max:10'],
                'identities.*' => ['required', 'array', 'max:10'],
                'account_id' => ['sometimes', 'integer', 'min:1'],
                'domain' => ['sometimes', 'string', 'max:253'],
                'external_identity' => ['sometimes', 'array', 'max:10'],
                'value' => ['sometimes', 'nullable', 'numeric'],
                'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            ]);
            $definition = $this->resolveDefinition($workspace->id, $data);
            $source = (string) ($data['source'] ?? 'manual');
            $key = (string) $data['idempotency_key'];
            unset($data['definition_id'], $data['definition_version_id'], $data['source'], $data['idempotency_key']);
            $result = $this->ingest->handle(
                $workspace,
                $definition,
                $this->boundedS2Payload($data, 'event'),
                $source,
                null,
                $user,
                $key,
            );

            return $this->intakeResponse($result);
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }

    /** @param array<string,mixed> $data */
    private function resolveDefinition(int|string $workspaceId, array $data): ConversionDefinition|ConversionDefinitionVersion
    {
        $versionId = $data['definition_version_id'] ?? null;
        if (is_string($versionId) && $versionId !== '') {
            return ConversionDefinitionVersion::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($versionId)
                ->firstOrFail();
        }
        $definitionId = $data['definition_id'] ?? null;
        if (! is_string($definitionId) || trim($definitionId) === '') {
            throw ValidationException::withMessages(['definition_id' => 'A Conversion Definition is required.']);
        }

        return ConversionDefinition::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($definitionId)
            ->firstOrFail();
    }

    private function intakeResponse(ConversionIntakeResult $result): Response
    {
        return $this->json([
            'duplicate' => $result->duplicate,
            'receipt_id' => (string) $result->receipt->id,
            'event' => $result->event === null
                ? null
                : (new ConversionEventResource($result->event->load(['matches', 'reviewEvents'])))->resolve(),
        ]);
    }
}
