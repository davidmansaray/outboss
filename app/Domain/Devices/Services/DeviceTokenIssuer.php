<?php

namespace App\Domain\Devices\Services;

use App\Domain\Devices\Support\DeviceScopes;
use App\Domain\Devices\Support\IssuedDeviceTokens;
use App\Models\DeviceRefreshToken;
use App\Models\DeviceSession;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mints a workspace-bound Sanctum access token and a single-use refresh
 * token for a device session. Earlier access tokens for the session are
 * deleted so a phone holds at most one live access token.
 */
class DeviceTokenIssuer
{
    public function issue(DeviceSession $session, Workspace $workspace): IssuedDeviceTokens
    {
        return DB::transaction(function () use ($session, $workspace): IssuedDeviceTokens {
            /** @var User $user */
            $user = $session->user()->firstOrFail();
            $accessExpiresAt = now()->addMinutes((int) config('outboss.mobile.access_token_ttl_minutes', 60));
            $refreshExpiresAt = now()->addDays((int) config('outboss.mobile.refresh_token_ttl_days', 60));

            $session->accessTokens()->delete();

            $secret = $user->generateTokenString();
            /** @var PersonalAccessToken $accessToken */
            $accessToken = $user->tokens()->create([
                'name' => 'iOS: '.$session->name,
                'token' => hash('sha256', $secret),
                'abilities' => DeviceScopes::all(),
                'expires_at' => $accessExpiresAt,
                'workspace_id' => $workspace->id,
                'device_session_id' => $session->id,
                'display_prefix' => PersonalAccessToken::displayPrefixFrom($secret),
            ]);

            $refreshSecret = 'ort_'.Str::random(64);
            $refreshToken = DeviceRefreshToken::query()->create([
                'device_session_id' => $session->id,
                'token_hash' => hash('sha256', $refreshSecret),
                'expires_at' => $refreshExpiresAt,
            ]);

            $session->forceFill([
                'workspace_id' => $workspace->id,
                'last_used_at' => now(),
            ])->save();

            return new IssuedDeviceTokens(
                accessToken: $accessToken->getKey().'|'.$secret,
                accessTokenExpiresAt: $accessExpiresAt,
                refreshToken: $refreshSecret,
                refreshTokenId: (int) $refreshToken->getKey(),
                refreshTokenExpiresAt: $refreshExpiresAt,
                workspaceId: (int) $workspace->id,
                deviceSessionId: $session->id,
            );
        });
    }
}
