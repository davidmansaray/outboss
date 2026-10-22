<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Http\Resources\Api\V1\CampaignRecipientResource;
use App\Models\Campaign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists recipients enrolled in a campaign. Optional status filter (queued, active, paused, and other recipient lifecycle values). Defaults to 20 results (maximum 100). Does not mutate data.')]
#[IsReadOnly]
class ListCampaignRecipients extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()
                ->required()
                ->description('The campaign whose recipients should be listed.'),
            'status' => $schema->string()
                ->enum(RecipientStatus::class)
                ->description('If set, only recipients with this lifecycle status are returned.'),
            ...$this->paginationSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsRead->value)) {
            return $denied;
        }

        $campaign = $this->authorised($request, Campaign::class, $request->integer('campaign_id'));
        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        $status = $request->get('status');

        $recipients = $campaign->recipients()
            ->where('workspace_id', $workspaceId)
            ->with('mailbox')
            ->when(
                is_string($status) && $status !== '',
                function ($query) use ($status): void {
                    $enum = RecipientStatus::tryFrom($status);

                    if ($enum !== null) {
                        $query->where('status', $enum);
                    }
                },
            )
            ->orderBy('id')
            ->paginate($this->perPage($request), ['*'], 'page', $this->page($request));

        return $this->json($this->pagePayload(
            CampaignRecipientResource::collection($recipients->getCollection())->resolve(),
            $recipients->currentPage(),
            $recipients->perPage(),
            $recipients->total(),
        ));
    }
}
