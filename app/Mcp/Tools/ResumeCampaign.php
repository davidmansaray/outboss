<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Exceptions\GovernedCampaignInputMutation;
use App\Domain\Campaigns\Exceptions\GovernedCampaignLaunchRequired;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Http\Resources\Api\V1\CampaignResource;
use App\Models\Campaign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Sends real email: resumes a paused or completed campaign and continues sending to remaining recipients. Confirm with the user before calling. The campaign must still pass launch readiness.')]
#[IsDestructive]
#[IsOpenWorld]
class ResumeCampaign extends OutbossTool
{
    public function __construct(private LaunchCampaignService $launch) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()
                ->required()
                ->description('The campaign to resume. Confirm with the user first.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsLaunch->value)) {
            return $denied;
        }

        $campaign = $this->authorised($request, Campaign::class, $request->integer('campaign_id'), 'update');
        try {
            $campaign = $this->launch->resume($campaign, $this->actor($request))->load([
                'draftVersion.activeSteps',
                'publishedVersion.activeSteps',
                'mailboxes',
            ]);
        } catch (GovernedCampaignInputMutation|GovernedCampaignLaunchRequired $exception) {
            return $this->governedCampaignError($exception);
        }

        return $this->json((new CampaignResource($campaign))->resolve());
    }
}
