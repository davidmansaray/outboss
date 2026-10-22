<?php

namespace App\Domain\Conversions\Enums;

enum ConversionVerificationState: string
{
    case NotRequired = 'not_required';
    case Verified = 'verified';
    case Unverified = 'unverified';
    case Failed = 'failed';
}
