<?php

namespace App\Domain\Prospecting\Enums;

enum ProspectSourceStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    public function acceptsRuns(): bool
    {
        return $this === self::Active;
    }
}
