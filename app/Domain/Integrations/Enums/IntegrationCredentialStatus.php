<?php

namespace App\Domain\Integrations\Enums;

enum IntegrationCredentialStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Retiring = 'retiring';
    case Wiped = 'wiped';

    public function usable(): bool
    {
        return $this === self::Active;
    }
}
