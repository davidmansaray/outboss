<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Http\Resources\Api\V1\ConversionEventResource;
use App\Models\ConversionEvent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists safe Conversion Event projections in the authenticated workspace. Raw receipts, unmatched identity envelopes, and provider payloads are omitted.')]
#[IsReadOnly]
class ListConversionEvents extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'state' => $schema->string()->description('Optional state filter.'),
            'account_id' => $schema->integer()->min(1)->description('Optional matched Account id.'),
            'definition_id' => $schema->string()->description('Optional Conversion Definition UUID.'),
            ...$this->paginationSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::ConversionsRead->value)) {
            return $denied;
        }

        try {
            $workspace = $this->actor($request)->currentWorkspace();
            $events = ConversionEvent::query()
                ->where('workspace_id', $workspace->id)
                ->when($request->filled('state'), fn ($query) => $query->where('state', (string) $request->get('state')))
                ->when($request->filled('account_id'), fn ($query) => $query->where('matched_account_id', $request->integer('account_id')))
                ->when($request->filled('definition_id'), fn ($query) => $query->where('conversion_definition_id', (string) $request->get('definition_id')))
                ->with(['matches', 'reviewEvents'])
                ->latest('occurred_at')
                ->paginate($this->perPage($request), ['*'], 'page', $this->page($request));

            return $this->json($this->pagePayload(
                ConversionEventResource::collection($events->getCollection())->resolve(),
                $events->currentPage(),
                $events->perPage(),
                $events->total(),
            ));
        } catch (\Throwable $exception) {
            return $this->s2Failure($exception);
        }
    }
}
