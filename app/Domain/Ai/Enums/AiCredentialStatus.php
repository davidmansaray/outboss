<?php

namespace App\Domain\Ai\Enums;

enum AiCredentialStatus: string
{
    case Healthy = 'healthy';
    case RateLimited = 'rate_limited';
    case Misconfigured = 'misconfigured';
    case Disabled = 'disabled';
    case Removed = 'removed';
}
