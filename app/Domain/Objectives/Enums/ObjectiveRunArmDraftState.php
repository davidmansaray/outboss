<?php

namespace App\Domain\Objectives\Enums;

enum ObjectiveRunArmDraftState: string
{
    case Planned = 'planned';
    case AwaitingProvider = 'awaiting_provider';
    case Succeeded = 'succeeded';
    case Abstained = 'abstained';
    case NeedsReview = 'needs_review';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function terminal(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::Abstained,
            self::NeedsReview,
            self::Failed,
            self::Cancelled,
        ], true);
    }
}
