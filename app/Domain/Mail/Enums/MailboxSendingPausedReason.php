<?php

namespace App\Domain\Mail\Enums;

enum MailboxSendingPausedReason: string
{
    case BounceThreshold = 'bounce_threshold';
    case ProviderErrorThreshold = 'provider_error_threshold';
}
