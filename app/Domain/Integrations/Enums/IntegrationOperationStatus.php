<?php

namespace App\Domain\Integrations\Enums;

enum IntegrationOperationStatus: string
{
    case Planned = 'planned';
    case Reserved = 'reserved';
    case Executing = 'executing';
    case Awaiting = 'awaiting';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
    case Ambiguous = 'ambiguous';
    case Reconciling = 'reconciling';
    case Reconciled = 'reconciled';
    case ReconciliationPending = 'reconciliation_pending';
    case Cancelled = 'cancelled';
    case ManualReview = 'manual_review';

    public function terminal(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::Failed,
            self::Reconciled,
            self::Cancelled,
            self::ManualReview,
        ], true);
    }

    public function mayHaveReachedProvider(): bool
    {
        return in_array($this, [
            self::Executing,
            self::Awaiting,
            self::Uncertain,
            self::Ambiguous,
            self::Reconciling,
            self::ReconciliationPending,
        ], true);
    }
}
