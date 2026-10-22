<?php

namespace App\Domain\Ai\Enums;

enum AiOperationStatus: string
{
    case Planned = 'planned';
    case Reserved = 'reserved';
    case Executing = 'executing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
    case Reconciling = 'reconciling';
    case Reconciled = 'reconciled';
    case Cancelled = 'cancelled';
    case ManualReview = 'manual_review';

    public function terminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Reconciled, self::Cancelled, self::ManualReview], true);
    }
}
