<?php

namespace App\Domain\Gtm\Enums;

enum OfferStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => $target === self::Active,
            self::Active => $target === self::Paused,
            self::Paused => in_array($target, [self::Active, self::Archived], true),
            self::Archived => $target === self::Paused,
        };
    }
}
