<?php

namespace App\Domain\Intelligence\Enums;

enum AccountResearchStageState: string
{
    case Pending = 'pending';
    case Blocked = 'blocked';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case PaidResultLost = 'paid_result_lost';
    case Cancelled = 'cancelled';
}
