<?php

namespace App\Domain\Prospecting\Enums;

enum SourceRunState: string
{
    case Draft = 'draft';
    case Previewed = 'previewed';
    case Queued = 'queued';
    case Running = 'running';
    case Partial = 'partial';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case ReconciliationPending = 'reconciliation_pending';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Failed,
            self::Cancelled,
            self::Expired,
        ], true);
    }

    public function canAcceptObservations(): bool
    {
        return in_array($this, [self::Draft, self::Previewed, self::Queued, self::Running, self::Partial], true);
    }
}
