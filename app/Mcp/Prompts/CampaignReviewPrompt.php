<?php

namespace App\Mcp\Prompts;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\Services\CampaignLaunchReadiness;
use App\Domain\Campaigns\Services\CampaignOutcomeCounts;
use App\Http\Resources\Api\V1\CampaignResource;
use App\Mcp\Concerns\ChecksScopes;
use App\Models\Campaign;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('campaign-review')]
#[Description('Review a campaign sequence, audience, mailboxes, and launch readiness before sending. Confirm with the user before calling LaunchCampaign.')]
class CampaignReviewPrompt extends Prompt
{
    use ChecksScopes;

    public function __construct(
        private CampaignLaunchReadiness $readiness,
        private CampaignOutcomeCounts $outcomes,
    ) {}

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('campaign_id', 'The campaign id to review before launch.', required: true),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsRead->value)) {
            return $denied;
        }

        $data = $request->validate([
            'campaign_id' => ['required', 'integer'],
        ]);
        $campaign = Campaign::query()->find((int) $data['campaign_id']);

        if (! $campaign instanceof Campaign) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        Gate::forUser($this->actor($request))->authorize('view', $campaign);

        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        $campaign->load([
            'draftVersion.activeSteps',
            'publishedVersion.activeSteps',
            'mailboxes' => fn ($query) => $query->where('mailboxes.workspace_id', $workspaceId),
        ]);

        $payload = [
            'campaign' => (new CampaignResource($campaign))->resolve(),
            'counts' => $this->outcomes->counts($campaign),
            'readiness' => [
                'ready' => ($blockers = $this->readiness->blockers($campaign)) === [],
                'blockers' => $blockers,
            ],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        return Response::text(<<<MARKDOWN
You are reviewing an Outboss campaign before launch.

Explain whether the campaign is ready to send. Cover audience, sequence (steps, delays, subjects, variables), and sending (mailboxes, send mode, domain auth blockers). List remaining blockers in plain British English.

Do not call LaunchCampaign or ResumeCampaign until the user confirms. Tools that send real email must stay unused during this review.

Campaign JSON:
{$json}
MARKDOWN);
    }
}
