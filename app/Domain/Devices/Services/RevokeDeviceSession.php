<?php

namespace App\Domain\Devices\Services;

use App\Models\DeviceSession;
use Illuminate\Support\Facades\DB;

/**
 * Signs a phone out. Refresh token rows are kept until pruning so a later
 * refresh attempt reports session_revoked instead of an unknown token.
 */
class RevokeDeviceSession
{
    public function handle(DeviceSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $session->forceFill([
                'revoked_at' => $session->revoked_at ?? now(),
                'apns_token' => null,
                'apns_environment' => null,
            ])->save();

            $session->accessTokens()->delete();
        });
    }
}
