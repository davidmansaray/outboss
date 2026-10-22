<?php

namespace App\Domain\Conversions\Enums;

enum ConversionEventState: string
{
    case Received = 'received';
    case Matched = 'matched';
    case Ambiguous = 'ambiguous';
    case Unmatched = 'unmatched';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';
    case Corrected = 'corrected';
    case Reversed = 'reversed';
}
