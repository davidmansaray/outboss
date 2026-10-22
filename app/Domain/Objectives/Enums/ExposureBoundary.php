<?php

namespace App\Domain\Objectives\Enums;

enum ExposureBoundary: string
{
    case ProviderAccepted = 'provider_accepted';
    case Sent = 'sent';
}
