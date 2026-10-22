<?php

namespace App\Domain\Devices\Support;

use App\Domain\Devices\Exceptions\DeviceAuthException;
use App\Models\DeviceSession;
use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;

final class CurrentDeviceSession
{
    /** The device session behind this request's access token, or a 403 for API keys. */
    public static function require(Request $request): DeviceSession
    {
        $token = $request->user()?->currentAccessToken();
        $session = $token instanceof PersonalAccessToken && $token->isDeviceToken()
            ? DeviceSession::query()->active()->find($token->device_session_id)
            : null;

        return $session ?? throw DeviceAuthException::deviceSessionRequired();
    }
}
