<?php

namespace App\Domain\Objectives\Enums;

enum ObjectiveStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Active = 'active';
    case Blocked = 'blocked';
    case Paused = 'paused';
    case Completed = 'completed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, match ($this) {
            self::Draft => [self::Ready, self::Cancelled],
            self::Ready => [self::Active, self::Blocked, self::Cancelled, self::Expired],
            self::Active => [self::Paused, self::Blocked, self::Completed, self::Cancelled, self::Expired],
            self::Paused => [self::Active, self::Blocked, self::Cancelled, self::Expired],
            self::Blocked => [self::Active, self::Cancelled, self::Expired],
            self::Completed, self::Expired, self::Cancelled => [],
        }, true);
    }
}
