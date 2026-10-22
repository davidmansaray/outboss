<?php

namespace App\Domain\Integrations\Enums;

enum ExternalOperationAttemptStatus: string
{
    case Started = 'started';
    case Completed = 'completed';
    case Failed = 'failed';
    case DefinitelyNotSent = 'definitely_not_sent';
    case Ambiguous = 'ambiguous';
    case Reconciled = 'reconciled';
}
