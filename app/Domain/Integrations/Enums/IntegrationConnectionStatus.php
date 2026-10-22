<?php

namespace App\Domain\Integrations\Enums;

enum IntegrationConnectionStatus: string
{
    case Pending = 'pending';
    case Healthy = 'healthy';
    case RateLimited = 'rate_limited';
    case Misconfigured = 'misconfigured';
    case Disabled = 'disabled';
    case Removed = 'removed';

    public function usable(): bool
    {
        return $this === self::Healthy;
    }
}
