<?php

namespace App\Domain\Devices\Services;

use App\Domain\Devices\Exceptions\DeviceAuthException;
use App\Domain\Devices\Support\IssuedDeviceTokens;
use App\Domain\Devices\Support\Pkce;
use App\Models\DeviceSession;
use App\Models\MobileAuthorizationCode;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class ExchangeAuthorizationCode
{
    public function __construct(private DeviceTokenIssuer $issuer) {}

    /**
     * @param  array{install_id: string, name: string, platform: string, app_version?: string|null}  $device
     */
    public function handle(string $code, string $codeVerifier, string $redirectUri, array $device): IssuedDeviceTokens
    {
        $record = MobileAuthorizationCode::query()
            ->where('code_hash', hash('sha256', $code))
            ->first();

        if ($record === null) {
            throw DeviceAuthException::invalidGrant();
        }

        // Claim before checking the verifier: a code is spent by any attempt,
        // so a guessed verifier cannot be retried against the same code.
        $claimed = MobileAuthorizationCode::query()
            ->whereKey($record->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);

        if ($claimed !== 1
            || ! hash_equals($record->redirect_uri, $redirectUri)
            || ! Pkce::verifies($codeVerifier, $record->code_challenge)) {
            throw DeviceAuthException::invalidGrant();
        }

        $user = User::query()->find($record->user_id);
        $workspace = $user?->workspaces()->whereKey($record->workspace_id)->first();

        if (! $user instanceof User || ! $workspace instanceof Workspace) {
            throw DeviceAuthException::workspaceForbidden();
        }

        return DB::transaction(function () use ($user, $workspace, $device, $record): IssuedDeviceTokens {
            // Signing in again on the same phone replaces its earlier session.
            DeviceSession::query()
                ->where('user_id', $user->id)
                ->where('install_id', $device['install_id'])
                ->delete();

            $session = DeviceSession::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'install_id' => $device['install_id'],
                'name' => $device['name'] !== '' ? $device['name'] : ($record->device_name ?? 'iPhone'),
                'platform' => $device['platform'],
                'app_version' => $device['app_version'] ?? null,
            ]);

            return $this->issuer->issue($session, $workspace);
        });
    }
}
