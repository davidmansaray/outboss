<?php

namespace App\Domain\Conversions\Enums;

enum ConversionMatchResult: string
{
    case Matched = 'matched';
    case Ambiguous = 'ambiguous';
    case Unmatched = 'unmatched';
    case Rejected = 'rejected';
}
