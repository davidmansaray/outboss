<?php

namespace App\Domain\Mail\Enums;

enum MessageStatus: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Sending = 'sending';
    case ProviderAccepted = 'provider_accepted';
    case Sent = 'sent';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
