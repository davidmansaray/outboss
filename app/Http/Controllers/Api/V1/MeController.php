<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\PushConfiguration;
use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request, PushConfiguration $push): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentWorkspace();
        $token = $user->currentAccessToken();
        $workspaces = $user->workspaces()
            ->withPivot('role')
            ->orderBy('workspaces.name')
            ->get()
            ->map(fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'role' => $workspace->getRelation('pivot')?->getAttribute('role') ?? WorkspaceMembership::ROLE_OWNER,
            ])
            ->values();

        $isDevice = $token instanceof PersonalAccessToken && $token->isDeviceToken();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'timezone' => $user->timezone,
                ],
                'workspace' => $workspaces->firstWhere('id', $current->id),
                'workspaces' => $workspaces->all(),
                'session' => [
                    'kind' => $isDevice ? 'device' : 'api_key',
                    'id' => $isDevice ? $token->device_session_id : null,
                    'scopes' => $token instanceof PersonalAccessToken && is_array($token->abilities)
                        ? $token->abilities
                        : [],
                    'expires_at' => $token instanceof PersonalAccessToken
                        ? $token->expires_at?->toIso8601String()
                        : null,
                ],
                'capabilities' => [
                    'push' => $push->available(),
                ],
            ],
        ]);
    }
}
