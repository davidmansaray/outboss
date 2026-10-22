<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Conversions\Services\CorrectConversionEvent as CorrectService;
use App\Http\Resources\Api\V1\ConversionEventResource;
use App\Models\ConversionEvent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Appends a bounded corrected successor Conversion Event while preserving the original event and lineage.')]
class CorrectConversionEvent extends OutbossTool
{
    public function __construct(private readonly CorrectService $correct) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->string()->required()->description('The original Conversion Event UUID.'),
            'changes' => $schema->object()->required()->description('Bounded allowlisted event fields to correct.'),
            'reason' => $schema->string()->max(1000)->description('Optional correction reason.'),
            'idempotency_key' => $schema->string()->max(160)->description('Optional stable correction retry key.'),
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
                'changes' => ['required', 'array', 'max:20'],
                'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:160'],
            ]);
            $updated = $this->correct->handle(
                $event,
                $user,
                $this->boundedS2Payload($data['changes'], 'changes'),
                $data['reason'] ?? null,
                $data['idempotency_key'] ?? null,
            );

            return $this->json((new ConversionEventResource($updated->load(['matches', 'reviewEvents'])))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
