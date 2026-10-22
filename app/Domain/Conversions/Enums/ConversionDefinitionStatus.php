<?php

namespace App\Domain\Conversions\Enums;

enum ConversionDefinitionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function acceptsIntake(): bool
    {
        return $this === self::Active;
    }
}
