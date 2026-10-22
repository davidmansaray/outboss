<?php

namespace App\Domain\Gtm\Enums;

enum GtmProfileReviewStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
