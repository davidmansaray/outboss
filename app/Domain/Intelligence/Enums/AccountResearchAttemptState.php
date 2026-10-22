<?php

namespace App\Domain\Intelligence\Enums;

enum AccountResearchAttemptState: string
{
    case Planned = 'planned';
    case Running = 'running';
    case ProviderBoundary = 'provider_boundary';
    case WaitingBudget = 'waiting_budget';
    case Ambiguous = 'ambiguous';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case DefinitelyNotSent = 'definitely_not_sent';
    case Cancelled = 'cancelled';
}
