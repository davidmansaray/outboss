<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Conversions\Services\ReviewConversionEvent as ReviewService;
use App\Http\Resources\Api\V1\ConversionEventResource;
use App\Models\ConversionEvent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Resolves or rejects one unmatched/ambiguous Conversion Event. Resolution is restricted to an Account in the authenticated workspace and is auditable.')]
class ReviewConversionEvent extends OutbossTool
{
    public function __construct(private readonly ReviewService $review) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->string()->required()->description('The Conversion Event UUID.'),
            'action' => $schema->string()->required()->description('resolve or reject.'),
            'account_id' => $schema->integer()->min(1)->description('Required for resolve; Account id in this workspace.'),
            'reason' => $schema->string()->max(1000)->description('Optional bounded review reason.'),
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
                'action' => ['required', 'string', 'in:resolve,reject'],
                'account_id' => ['required_if:action,resolve', 'nullable', 'integer', 'min:1'],
                'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ]);
            if ($data['action'] === 'resolve') {
                $workspace = $user->currentWorkspace();
                if (! $workspace->accounts()->whereKey((int) $data['account_id'])->exists()) {
                    throw ValidationException::withMessages(['account_id' => 'The Account must belong to this workspace.']);
                }
            }
            $updated = $this->review->handle(
                $event,
                $user,
                $data['action'],
                isset($data['account_id']) ? (int) $data['account_id'] : null,
                $data['reason'] ?? null,
            );

            return $this->json((new ConversionEventResource($updated->load(['matches', 'reviewEvents'])))->resolve());
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
