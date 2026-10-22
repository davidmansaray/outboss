<?php

namespace App\Domain\Devices\Services;

use App\Domain\Devices\Exceptions\DeviceAuthException;
use App\Models\MobileAuthorizationCode;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

class IssueMobileAuthorizationCode
{
    /**
     * @return string The plain code. Only its hash is stored.
     */
    public function handle(User $user, Workspace $workspace, string $codeChallenge, string $redirectUri, ?string $deviceName): string
    {
        if (! $user->workspaces()->whereKey($workspace->id)->exists()) {
            throw DeviceAuthException::workspaceForbidden();
        }

        $code = 'moc_'.Str::random(48);

        MobileAuthorizationCode::query()->create([
            'code_hash' => hash('sha256', $code),
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'code_challenge' => $codeChallenge,
            'redirect_uri' => $redirectUri,
            'device_name' => $deviceName,
            'expires_at' => now()->addSeconds((int) config('outboss.mobile.authorization_code_ttl_seconds', 60)),
        ]);

        return $code;
    }
}
