<?php

namespace App\Domain\Gtm\Enums;

enum GtmProfileResearchState: string
{
    case NotStarted = 'not_started';
    case Queued = 'queued';
    case Running = 'running';
    case AwaitingProvider = 'awaiting_provider';
    case AwaitingBudget = 'awaiting_budget';
    case Ready = 'ready';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
