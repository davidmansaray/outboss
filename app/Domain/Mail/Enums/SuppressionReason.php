<?php

namespace App\Domain\Mail\Enums;

enum SuppressionReason: string
{
    case Unsubscribe = 'unsubscribe';
    case HardBounce = 'hard_bounce';
    case Manual = 'manual';
}
