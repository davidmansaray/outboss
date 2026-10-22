<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Http\Resources\Api\V1\CampaignResource;
use App\Models\Campaign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Pauses an active campaign. Recipients stop receiving further sequence mail until ResumeCampaign is used. Does not send email.')]
class PauseCampaign extends OutbossTool
{
    public function __construct(private LaunchCampaignService $launch) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()
                ->required()
                ->description('The campaign to pause.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsWrite->value)) {
            return $denied;
        }

        $campaign = $this->authorised($request, Campaign::class, $request->integer('campaign_id'), 'update');
        $campaign = $this->launch->pause($campaign)->load([
            'draftVersion.activeSteps',
            'publishedVersion.activeSteps',
            'mailboxes',
        ]);

        return $this->json((new CampaignResource($campaign))->resolve());
    }
}
