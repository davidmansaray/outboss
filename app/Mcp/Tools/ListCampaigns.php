<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Http\Resources\Api\V1\CampaignSummaryResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists campaigns in the authenticated workspace. Optional status filter (draft, active, paused, completed). Defaults to 20 results (maximum 100); use page and per_page when more exist. Does not mutate data.')]
#[IsReadOnly]
class ListCampaigns extends OutbossTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(CampaignStatus::class)
                ->description('If set, only campaigns with this status are returned.'),
            ...$this->paginationSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsRead->value)) {
            return $denied;
        }

        $user = $this->actor($request);
        $workspace = $user->currentWorkspace();
        $workspaceId = (int) $workspace->id;
        $status = $request->get('status');
        $perPage = $this->perPage($request);

        $campaigns = $workspace
            ->campaigns()
            ->with([
                'draftVersion.activeSteps',
                'publishedVersion.activeSteps',
                'mailboxes' => fn ($query) => $query
                    ->where('mailboxes.workspace_id', $workspaceId),
            ])
            ->withCount([
                'recipients as people_count' => fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->where('status', '!=', RecipientStatus::Removed),
                'recipients as replies_count' => fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->replied(),
                'recipients as interested_count' => fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->interested(),
                'recipients as sent_count' => fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->sent(),
                'recipients as queued_count' => fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->queued(),
                'recipients as bounced_count' => fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->bounced(),
                'recipients as unsubscribed_count' => fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->unsubscribed(),
            ])
            ->when(
                is_string($status) && $status !== '',
                function ($query) use ($status): void {
                    $enum = CampaignStatus::tryFrom($status);

                    if ($enum !== null) {
                        $query->where('status', $enum);
                    }
                },
            )
            ->latest('updated_at')
            ->paginate($perPage, ['*'], 'page', $this->page($request));

        return $this->json($this->pagePayload(
            CampaignSummaryResource::collection($campaigns->getCollection())->resolve(),
            $campaigns->currentPage(),
            $campaigns->perPage(),
            $campaigns->total(),
        ));
    }
}
