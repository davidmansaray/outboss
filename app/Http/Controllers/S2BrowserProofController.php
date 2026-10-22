<?php

namespace App\Http\Controllers;

use App\Models\AccountResearchRun;
use App\Models\AiProviderOperation;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ConversionEvent;
use App\Models\FulfilmentCapacityReservation;
use App\Models\FulfilmentRequest;
use App\Models\Message;
use App\Models\SendAttempt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Safe count-only evidence for the isolated deterministic browser harness.
 *
 * The route is registered only in the explicit testing + fake-provider
 * environment. It never exists in a normal development or production app.
 */
final class S2BrowserProofController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(app()->environment('testing') && config('intelligence.testing.fake_providers'), 404);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $workspace = $user->currentWorkspace();
        abort_unless($workspace->isOwner($user), 403);

        return response()->json(['data' => [
            'workspace_id' => (int) $workspace->id,
            'owner_user_id' => (int) $user->id,
            'account_research_runs' => AccountResearchRun::query()->where('workspace_id', $workspace->id)->count(),
            'provider_operations' => AiProviderOperation::query()->where('workspace_id', $workspace->id)->count(),
            'conversion_events' => ConversionEvent::query()->where('workspace_id', $workspace->id)->count(),
            'fulfilment_requests' => FulfilmentRequest::query()->where('workspace_id', $workspace->id)->count(),
            'capacity_reservations' => FulfilmentCapacityReservation::query()->where('workspace_id', $workspace->id)->count(),
            'campaigns' => Campaign::query()->where('workspace_id', $workspace->id)->count(),
            'campaign_recipients' => CampaignRecipient::query()->where('workspace_id', $workspace->id)->count(),
            'messages' => Message::query()->where('workspace_id', $workspace->id)->count(),
            'send_attempts' => SendAttempt::query()->where('workspace_id', $workspace->id)->count(),
            'queued_jobs' => DB::table('jobs')->count(),
        ]]);
    }
}
