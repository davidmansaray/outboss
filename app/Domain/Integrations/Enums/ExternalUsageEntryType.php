<?php

namespace App\Domain\Integrations\Enums;

enum ExternalUsageEntryType: string
{
    case Charge = 'charge';
    case Release = 'release';
    case Adjustment = 'adjustment';
}
