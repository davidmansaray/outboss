<?php

namespace App\Domain\Gtm\Enums;

enum GtmProfileAwaitingReason: string
{
    case Provider = 'provider';
    case Budget = 'budget';
}
