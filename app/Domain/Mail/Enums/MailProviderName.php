<?php

namespace App\Domain\Mail\Enums;

enum MailProviderName: string
{
    case Gmail = 'gmail';
    case Microsoft = 'microsoft';
    case Zoho = 'zoho';
    case Stalwart = 'stalwart';
}
