<?php

namespace App\Domain\Prospecting\Enums;

enum ExternalIdentityState: string
{
    case Active = 'active';
    case Superseded = 'superseded';
    case Restricted = 'restricted';
    case Deleted = 'deleted';
}
