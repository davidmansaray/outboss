<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\RecipientStatus;
use App\Domain\Campaigns\Enums\SendMode;
use App\Domain\Campaigns\Services\CampaignLaunchReadiness;
use App\Domain\Campaigns\Services\CreateCampaignService;
use App\Domain\Campaigns\Services\GovernedCampaignInputGuard;
use App\Domain\Campaigns\Services\LaunchCampaignService;
use App\Domain\Mail\Exceptions\FrozenMailExperimentInputMutation;
use App\Domain\Mail\Services\FrozenMailExperimentInputGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\LaunchCampaignRequest;
use App\Http\Requests\UpdateCampaignRequest;
use App\Http\Resources\Api\V1\CampaignResource;
use App\Http\Resources\Api\V1\CampaignSummaryResource;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class CampaignController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $workspace = $request->user()->currentWorkspace();
        $workspaceId = (int) $workspace->id;
        $status = $request->query('status');

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
            ->paginate($this->perPage($request))
            ->withQueryString();

        return CampaignSummaryResource::collection($campaigns);
    }

    public function store(Request $request, CreateCampaignService $create): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $campaign = $create->handle($request->user(), $data['name']);

        return (new CampaignResource($campaign))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Campaign $campaign): CampaignResource
    {
        $this->authorize('view', $campaign);
        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        $campaign->load([
            'draftVersion.activeSteps',
            'publishedVersion.activeSteps',
            'mailboxes' => fn ($query) => $query->where('mailboxes.workspace_id', $workspaceId),
        ]);

        return new CampaignResource($campaign);
    }

    public function update(
        UpdateCampaignRequest $request,
        Campaign $campaign,
        FrozenMailExperimentInputGuard $frozenInputs,
        GovernedCampaignInputGuard $governedInputs,
    ): CampaignResource {
        $data = $request->validated();
        $workspace = $request->user()->currentWorkspace();

        try {
            $governedInputs->assertCampaignMutable($campaign);
            $frozenInputs->assertCampaignMutable($campaign);
        } catch (FrozenMailExperimentInputMutation $exception) {
            throw ValidationException::withMessages(['campaign' => $exception->getMessage()]);
        }

        if (isset($data['name'])) {
            $campaign->forceFill(['name' => $data['name']])->save();
        }

        if (isset($data['send_mode'])) {
            $campaign->forceFill(['send_mode' => SendMode::from($data['send_mode'])])->save();
        }

        if (array_key_exists('open_tracking_enabled', $data)) {
            $campaign->forceFill(['open_tracking_enabled' => (bool) $data['open_tracking_enabled']])->save();
        }

        if (isset($data['mailbox_ids'])) {
            $owned = $workspace->mailboxes()->whereIn('id', $data['mailbox_ids'])->pluck('id');
            /** @var array<int, int|string> $mailboxIds */
            $mailboxIds = $data['mailbox_ids'];
            $ordered = collect($mailboxIds)
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $owned->contains($id))
                ->unique()
                ->values();
            $campaign->mailboxes()->sync(
                $ordered->mapWithKeys(fn ($id, $index) => [$id => ['position' => $index + 1]])->all()
            );
        }

        $campaign->refresh()->load([
            'draftVersion.activeSteps',
            'publishedVersion.activeSteps',
            'mailboxes',
        ]);

        return new CampaignResource($campaign);
    }

    public function launch(LaunchCampaignRequest $request, Campaign $campaign, LaunchCampaignService $launch): CampaignResource
    {
        return new CampaignResource($this->withSteps($launch->handle($campaign, $request->user())));
    }

    public function pause(LaunchCampaignRequest $request, Campaign $campaign, LaunchCampaignService $launch): CampaignResource
    {
        return new CampaignResource($this->withSteps($launch->pause($campaign)));
    }

    public function resume(LaunchCampaignRequest $request, Campaign $campaign, LaunchCampaignService $launch): CampaignResource
    {
        return new CampaignResource($this->withSteps($launch->resume($campaign, $request->user())));
    }

    public function complete(LaunchCampaignRequest $request, Campaign $campaign, LaunchCampaignService $launch): CampaignResource
    {
        return new CampaignResource($this->withSteps($launch->complete($campaign)));
    }

    public function launchReadiness(Campaign $campaign, CampaignLaunchReadiness $readiness): JsonResponse
    {
        $this->authorize('view', $campaign);
        $blockers = $readiness->blockers($campaign);

        return response()->json([
            'ready' => $blockers === [],
            'blockers' => $blockers,
        ]);
    }

    private function withSteps(Campaign $campaign): Campaign
    {
        return $campaign->load([
            'draftVersion.activeSteps',
            'publishedVersion.activeSteps',
            'mailboxes',
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, $request->integer('per_page', 15)));
    }
}
