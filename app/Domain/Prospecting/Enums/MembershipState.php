<?php

namespace App\Domain\Prospecting\Enums;

enum MembershipState: string
{
    case Active = 'active';
    case Restricted = 'restricted';
    case Suppressed = 'suppressed';
    case Expired = 'expired';
    case Removed = 'removed';
}
