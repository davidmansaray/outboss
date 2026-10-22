<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Devices\Services\ExchangeAuthorizationCode;
use App\Domain\Devices\Services\RefreshDeviceSession;
use App\Domain\Devices\Services\RevokeDeviceSession;
use App\Domain\Devices\Support\CurrentDeviceSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Token endpoint for the iOS app: trades a one-time sign-in code for tokens,
 * rotates refresh tokens (optionally switching workspace) and signs out.
 */
class AuthTokenController extends Controller
{
    public function store(
        Request $request,
        ExchangeAuthorizationCode $exchange,
        RefreshDeviceSession $refresh,
    ): JsonResponse {
        $grant = $request->validate([
            'grant_type' => ['required', 'in:authorization_code,refresh_token'],
        ])['grant_type'];

        if ($grant === 'refresh_token') {
            $data = $request->validate([
                'refresh_token' => ['required', 'string', 'max:255'],
                'workspace_id' => ['nullable', 'integer'],
            ]);

            $issued = $refresh->handle(
                $data['refresh_token'],
                isset($data['workspace_id']) ? (int) $data['workspace_id'] : null,
            );

            return response()->json(['data' => $issued->toArray()]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:255'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128'],
            'redirect_uri' => ['required', 'string', 'max:255'],
            'device' => ['required', 'array'],
            'device.install_id' => ['required', 'string', 'max:64'],
            'device.name' => ['required', 'string', 'max:120'],
            'device.platform' => ['required', 'in:ios'],
            'device.app_version' => ['nullable', 'string', 'max:40'],
        ]);

        $issued = $exchange->handle(
            $data['code'],
            $data['code_verifier'],
            $data['redirect_uri'],
            $data['device'],
        );

        return response()->json(['data' => $issued->toArray()]);
    }

    public function destroy(Request $request, RevokeDeviceSession $revoke): Response
    {
        $revoke->handle(CurrentDeviceSession::require($request));

        return response()->noContent();
    }
}
