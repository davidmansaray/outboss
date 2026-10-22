<?php

namespace App\Domain\Devices\Services;

use App\Models\DeviceRefreshToken;
use App\Models\DeviceSession;
use App\Models\MobileAuthorizationCode;

/**
 * Deletes sign-in records nobody can use any more. Spent refresh tokens are
 * kept for their full lifetime so late reuse is still detected as theft.
 */
class PruneDeviceAuthRecords
{
    /** @return array{codes: int, refresh_tokens: int, sessions: int} */
    public function handle(): array
    {
        $codes = MobileAuthorizationCode::query()
            ->where('expires_at', '<', now()->subDay())
            ->delete();

        $refreshTokens = DeviceRefreshToken::query()
            ->where('expires_at', '<', now()->subDay())
            ->delete();

        $sessions = DeviceSession::query()
            ->whereNotNull('revoked_at')
            ->where('revoked_at', '<', now()->subDays((int) config('outboss.mobile.refresh_token_ttl_days', 60)))
            ->delete();

        return ['codes' => $codes, 'refresh_tokens' => $refreshTokens, 'sessions' => $sessions];
    }
}
