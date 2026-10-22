<?php

namespace App\Domain\Gtm\Enums;

enum GtmResearchPhaseStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
