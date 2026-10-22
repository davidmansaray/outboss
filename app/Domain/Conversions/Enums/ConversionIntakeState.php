<?php

namespace App\Domain\Conversions\Enums;

enum ConversionIntakeState: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';
}
