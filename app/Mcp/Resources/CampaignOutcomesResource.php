<?php

namespace App\Mcp\Resources;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Services\CampaignOutcomeCounts;
use App\Mcp\Concerns\ChecksScopes;
use App\Models\Campaign;
use BackedEnum;
use JsonException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('Summary outcome counts for campaigns in the authenticated workspace: queued, sent, replied, interested, bounced, unsubscribed, and needs_review. Capped at 100 campaigns.')]
#[Uri('outboss://campaigns/outcomes')]
#[MimeType('application/json')]
class CampaignOutcomesResource extends Resource
{
    use ChecksScopes;

    public function __construct(private CampaignOutcomeCounts $outcomes) {}

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsRead->value)) {
            return $denied;
        }

        $workspace = $this->actor($request)->currentWorkspace();
        $campaigns = Campaign::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get(['id', 'name', 'status', 'created_at', 'launched_at', 'completed_at', 'updated_at']);

        $payload = [
            'campaigns' => $campaigns->map(function (Campaign $campaign): array {
                $status = $campaign->getAttribute('status');

                return [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'status' => $status instanceof BackedEnum ? $status->value : (string) $status,
                    'counts' => $this->outcomes->counts($campaign),
                ];
            })->values()->all(),
            'truncated' => $campaigns->count() === 100,
        ];

        try {
            return Response::text(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException) {
            return Response::error('The resource could not encode its response.');
        }
    }
}
