<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Services\CampaignOutcomeCounts;
use App\Http\Resources\Api\V1\CampaignResource;
use App\Models\Campaign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns one campaign, including sequence steps, attached mailbox ids, and outcome counts (queued, sent, replied, interested, bounced, unsubscribed, needs_review). Does not mutate data.')]
#[IsReadOnly]
class GetCampaign extends OutbossTool
{
    public function __construct(private CampaignOutcomeCounts $outcomes) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()
                ->required()
                ->description('The campaign id to load.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsRead->value)) {
            return $denied;
        }

        $campaign = $this->authorised($request, Campaign::class, $request->integer('campaign_id'));
        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        $campaign->load([
            'draftVersion.activeSteps',
            'publishedVersion.activeSteps',
            'mailboxes' => fn ($query) => $query->where('mailboxes.workspace_id', $workspaceId),
        ]);

        return $this->json([
            ...(new CampaignResource($campaign))->resolve(),
            'counts' => $this->outcomes->counts($campaign),
        ]);
    }
}
