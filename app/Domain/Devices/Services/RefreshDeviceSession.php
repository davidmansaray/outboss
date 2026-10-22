<?php

namespace App\Domain\Devices\Services;

use App\Domain\Devices\Exceptions\DeviceAuthException;
use App\Domain\Devices\Support\IssuedDeviceTokens;
use App\Models\DeviceRefreshToken;
use App\Models\DeviceSession;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Rotates a refresh token and mints a new access token, optionally for a
 * different workspace. Reusing a spent refresh token outside the grace
 * window is treated as theft and revokes the whole device session.
 */
class RefreshDeviceSession
{
    public function __construct(
        private DeviceTokenIssuer $issuer,
        private RevokeDeviceSession $revoke,
    ) {}

    public function handle(string $refreshToken, ?int $workspaceId = null): IssuedDeviceTokens
    {
        $record = DeviceRefreshToken::query()
            ->where('token_hash', hash('sha256', $refreshToken))
            ->first();

        if ($record === null) {
            throw DeviceAuthException::invalidGrant();
        }

        $session = DeviceSession::query()->find($record->device_session_id);

        if ($session === null || $session->isRevoked()) {
            throw DeviceAuthException::sessionRevoked();
        }

        if ($record->expires_at->isPast()) {
            throw DeviceAuthException::invalidGrant();
        }

        // Resolve the workspace before spending the token, so a refused
        // switch leaves the current refresh token usable.
        $workspace = $this->workspaceFor($session, $workspaceId);

        $claimed = DeviceRefreshToken::query()
            ->whereKey($record->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        if ($claimed !== 1 && ! $this->withinReuseGrace($record)) {
            $this->revoke->handle($session);

            throw DeviceAuthException::sessionRevoked();
        }

        return DB::transaction(function () use ($session, $workspace, $record): IssuedDeviceTokens {
            $issued = $this->issuer->issue($session, $workspace);

            DeviceRefreshToken::query()
                ->whereKey($record->id)
                ->update(['replaced_by_id' => $issued->refreshTokenId]);

            return $issued;
        });
    }

    /**
     * A reuse counts as a lost response only when it is recent and the token
     * minted in its place was never used. That successor is retired so only
     * the pair issued now remains valid.
     */
    private function withinReuseGrace(DeviceRefreshToken $record): bool
    {
        $record->refresh();
        $grace = (int) config('outboss.mobile.refresh_reuse_grace_seconds', 30);

        if ($record->used_at === null || $record->used_at->lt(now()->subSeconds($grace))) {
            return false;
        }

        if ($record->replaced_by_id === null) {
            return false;
        }

        return DeviceRefreshToken::query()
            ->whereKey($record->replaced_by_id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;
    }

    private function workspaceFor(DeviceSession $session, ?int $workspaceId): Workspace
    {
        /** @var User $user */
        $user = $session->user()->firstOrFail();

        if ($workspaceId !== null) {
            return $user->workspaces()->whereKey($workspaceId)->first()
                ?? throw DeviceAuthException::workspaceForbidden();
        }

        $current = $session->workspace_id === null
            ? null
            : $user->workspaces()->whereKey($session->workspace_id)->first();

        // Someone removed from the workspace this phone was using lands in
        // their default workspace instead of being signed out.
        return $current
            ?? $user->workspaces()
                ->orderByRaw("case when workspaces.type = 'personal' then 0 else 1 end")
                ->orderBy('workspaces.id')
                ->first()
            ?? throw DeviceAuthException::workspaceForbidden();
    }
}
