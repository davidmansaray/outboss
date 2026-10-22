<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Http\Resources\Api\V1\CampaignResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Creates a draft campaign with a default Intro sequence step, empty Objective/Offer composition, and every ready workspace inbox already selected. Does not send email. New campaigns require an exact compiled and approved execution plan; generic LaunchCampaign cannot bypass this requirement.')]
class CreateCampaign extends OutbossTool
{
    public function __construct(private CreateCampaignService $create) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->required()
                ->description('Campaign name, up to 120 characters.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsWrite->value)) {
            return $denied;
        }

        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $campaign = $this->create->handle($this->actor($request), $data['name']);

        return $this->json((new CampaignResource($campaign))->resolve());
    }
}
