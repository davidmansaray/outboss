<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mail\Services\UniboxInbox;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxCountsController extends Controller
{
    public function __invoke(Request $request, UniboxInbox $inbox): JsonResponse
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace();
        $mailboxId = $request->integer('mailbox') ?: null;
        $campaign = $inbox->campaignFilter($request->query('campaign'), $workspace);

        return response()->json([
            'data' => [
                'folders' => $inbox->counts($workspace, $mailboxId, $campaign),
                'unread' => $inbox->unreadCounts($workspace, $mailboxId, $campaign),
                'badge' => $inbox->badgeFor($user),
            ],
        ]);
    }
}
