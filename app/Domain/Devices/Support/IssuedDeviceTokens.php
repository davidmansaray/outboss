<?php

namespace App\Domain\Devices\Support;

use Carbon\CarbonInterface;

final readonly class IssuedDeviceTokens
{
    public function __construct(
        public string $accessToken,
        public CarbonInterface $accessTokenExpiresAt,
        public string $refreshToken,
        public int $refreshTokenId,
        public CarbonInterface $refreshTokenExpiresAt,
        public int $workspaceId,
        public string $deviceSessionId,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => $this->accessToken,
            'expires_in' => max(0, (int) round(now()->diffInSeconds($this->accessTokenExpiresAt))),
            'refresh_token' => $this->refreshToken,
            'refresh_expires_in' => max(0, (int) round(now()->diffInSeconds($this->refreshTokenExpiresAt))),
            'workspace_id' => $this->workspaceId,
            'device_session_id' => $this->deviceSessionId,
        ];
    }
}
