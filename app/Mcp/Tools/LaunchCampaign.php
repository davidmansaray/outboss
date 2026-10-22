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

#[Description('Sends real email: publishes the sequence and starts sending to enrolled recipients. Confirm with the user before calling. Check GetCampaignLaunchReadiness first; launch fails when blockers remain.')]
#[IsDestructive]
#[IsOpenWorld]
class LaunchCampaign extends OutbossTool
{
    public function __construct(private LaunchCampaignService $launch) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()
                ->required()
                ->description('The campaign to launch. Confirm with the user first.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsLaunch->value)) {
            return $denied;
        }

        $campaign = $this->authorised($request, Campaign::class, $request->integer('campaign_id'), 'update');
        try {
            $campaign = $this->launch->handle($campaign, $this->actor($request))->load([
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
