<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Conversions\Services\CreateConversionDefinition as CreateService;
use App\Http\Resources\Api\V1\ConversionDefinitionResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Creates one bounded, versioned Account Conversion Definition in the authenticated workspace. A stable idempotency key is required; this does not configure connection secrets.')]
class CreateConversionDefinition extends OutbossTool
{
    public function __construct(private readonly CreateService $create) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_key' => $schema->string()->required()->max(100)->description('Lower snake-case event key.'),
            'display_name' => $schema->string()->required()->max(160)->description('Bounded display name.'),
            'description' => $schema->string()->max(5000)->description('Optional plain-text description.'),
            'subject_type' => $schema->string()->description('Account is the supported S2 subject type.'),
            'allowed_sources' => $schema->array()->max(20)->description('Allowed source types, for example manual and api.'),
            'property_schema' => $schema->object()->description('Bounded JSON object property schema.'),
            'allowed_identity_keys' => $schema->array()->max(20)->description('Allowed identity keys such as account_id and domain.'),
            'dedupe_strategy' => $schema->string()->description('external_id, source_and_external_id, payload_hash, or idempotency_key.'),
            'dedupe_configuration' => $schema->object()->description('Optional bounded dedupe configuration.'),
            'attribution_window' => $schema->object()->description('Optional attribution metadata.'),
            'value_policy' => $schema->object()->description('Optional value policy.'),
            'correction_policy' => $schema->object()->description('Optional correction policy.'),
            'reversal_policy' => $schema->object()->description('Optional reversal policy.'),
            'retention_policy' => $schema->object()->description('Optional identity-retention policy.'),
            'export_policy' => $schema->object()->description('Optional export policy.'),
            'idempotency_key' => $schema->string()->required()->max(160)->description('Stable retry key.'),
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
                'event_key' => ['required', 'string', 'max:100'],
                'display_name' => ['required', 'string', 'max:160'],
                'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'subject_type' => ['sometimes', 'string', 'in:Account,Person,Conversation,Objective'],
                'allowed_sources' => ['sometimes', 'array', 'max:20'],
                'allowed_sources.*' => ['string', 'in:manual,api,webhook,email_reply'],
                'property_schema' => ['sometimes', 'array', 'max:10'],
                'allowed_identity_keys' => ['sometimes', 'array', 'max:20'],
                'allowed_identity_keys.*' => ['string', 'in:account_id,external_identity,domain'],
                'dedupe_strategy' => ['sometimes', 'string', 'in:external_id,source_and_external_id,payload_hash,idempotency_key'],
                'dedupe_configuration' => ['sometimes', 'array', 'max:20'],
                'attribution_window' => ['sometimes', 'array', 'max:10'],
                'value_policy' => ['sometimes', 'array', 'max:5'],
                'correction_policy' => ['sometimes', 'array', 'max:5'],
                'reversal_policy' => ['sometimes', 'array', 'max:5'],
                'retention_policy' => ['sometimes', 'array', 'max:5'],
                'export_policy' => ['sometimes', 'array', 'max:5'],
                'idempotency_key' => ['required', 'string', 'max:160'],
            ]);
            $key = (string) $data['idempotency_key'];
            unset($data['idempotency_key']);
            $definition = $this->create->handle($workspace, $user, $this->boundedS2Payload($data, 'definition'), $key);

            return $this->json((new ConversionDefinitionResource($definition->load('currentVersion')))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
