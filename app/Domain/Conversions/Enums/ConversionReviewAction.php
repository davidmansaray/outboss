<?php

namespace App\Domain\Conversions\Enums;

enum ConversionReviewAction: string
{
    case Resolve = 'resolve';
    case Reject = 'reject';
    case Correct = 'correct';
    case Reverse = 'reverse';
    case Restore = 'restore';
}
