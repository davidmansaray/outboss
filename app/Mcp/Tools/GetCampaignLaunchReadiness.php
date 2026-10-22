<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Services\CampaignLaunchReadiness;
use App\Models\Campaign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Reports whether a campaign is ready to launch. Returns ready=true when there are no blockers across audience, sequence, and sending. Always check this before LaunchCampaign. Does not mutate data.')]
#[IsReadOnly]
class GetCampaignLaunchReadiness extends OutbossTool
{
    public function __construct(private CampaignLaunchReadiness $readiness) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()
                ->required()
                ->description('The campaign id to inspect.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsRead->value)) {
            return $denied;
        }

        $campaign = $this->authorised($request, Campaign::class, $request->integer('campaign_id'));
        $blockers = $this->readiness->blockers($campaign);

        return $this->json([
            'ready' => $blockers === [],
            'blockers' => $blockers,
        ]);
    }
}
