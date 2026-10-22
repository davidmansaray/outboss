<?php

namespace App\Domain\Gtm\Enums;

enum GtmProfileReviewType: string
{
    case Missing = 'missing';
    case Conflict = 'conflict';
    case Inferred = 'inferred';
    case Stale = 'stale';
}
