<?php

namespace App\Domain\Intelligence\Enums;

enum AccountResearchRunState: string
{
    case Planned = 'planned';
    case AwaitingProvider = 'awaiting_provider';
    case AwaitingBudget = 'awaiting_budget';
    case Running = 'running';
    case Paused = 'paused';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';
    case Partial = 'partial';
    case Completed = 'completed';
    case Failed = 'failed';

    public function terminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Partial, self::Completed, self::Failed], true);
    }

    public function isAwaiting(): bool
    {
        return in_array($this, [self::AwaitingProvider, self::AwaitingBudget], true);
    }
}
