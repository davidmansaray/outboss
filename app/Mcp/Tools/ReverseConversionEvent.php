<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Conversions\Services\ReverseConversionEvent as ReverseService;
use App\Http\Resources\Api\V1\ConversionEventResource;
use App\Models\ConversionEvent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Appends an idempotent reversal Conversion Event for a reversible event definition.')]
class ReverseConversionEvent extends OutbossTool
{
    public function __construct(private readonly ReverseService $reverse) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->string()->required()->description('The Conversion Event UUID.'),
            'reason' => $schema->string()->max(1000)->description('Optional reversal reason.'),
            'idempotency_key' => $schema->string()->max(160)->description('Optional stable reversal retry key.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::ConversionsWrite->value)) {
            return $denied;
        }

        try {
            $user = $this->actor($request);
            $event = $this->workspaceModel($request, ConversionEvent::class, (string) $request->get('event_id'));
            $data = $request->validate([
                'event_id' => ['required', 'uuid'],
                'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:160'],
            ]);
            $updated = $this->reverse->handle(
                $event,
                $user,
                $data['reason'] ?? null,
                $data['idempotency_key'] ?? null,
            );

            return $this->json((new ConversionEventResource($updated->load(['matches', 'reviewEvents'])))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
