<?php

namespace App\Domain\Prospecting\Enums;

enum ObservationDisposition: string
{
    case New = 'new';
    case Linked = 'linked';
    case Duplicate = 'duplicate';
    case Conflicted = 'conflicted';
    case Suppressed = 'suppressed';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case PendingReview = 'pending_review';

    public function countsAsAccepted(): bool
    {
        return in_array($this, [self::New, self::Linked, self::Duplicate], true);
    }

    public function countsAsValid(): bool
    {
        return $this !== self::Rejected;
    }
}
