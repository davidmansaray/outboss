<?php

namespace App\Domain\Conversions\Enums;

enum ConversionSourceType: string
{
    case Manual = 'manual';
    case Api = 'api';
    case Webhook = 'webhook';
    case EmailReply = 'email_reply';
}
