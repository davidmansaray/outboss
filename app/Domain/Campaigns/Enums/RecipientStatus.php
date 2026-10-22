<?php

namespace App\Domain\Campaigns\Enums;

enum RecipientStatus: string
{
    case Queued = 'queued';
    case Active = 'active';
    case Paused = 'paused';
    case BlockedMailbox = 'blocked_mailbox';
    case Completed = 'completed';
    case Replied = 'replied';
    case Interested = 'interested';
    case NotNow = 'not_now';
    case WrongPerson = 'wrong_person';
    case Bounced = 'bounced';
    case Unsubscribed = 'unsubscribed';
    case Removed = 'removed';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Replied,
            self::Interested,
            self::NotNow,
            self::WrongPerson,
            self::Bounced,
            self::Unsubscribed,
            self::Removed,
        ], true);
    }

    public function isCompletable(): bool
    {
        return $this === self::Completed || $this->isTerminal();
    }
}
