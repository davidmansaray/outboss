<?php

namespace App\Domain\Mail\Enums;

enum BounceSubtype: string
{
    case Hard = 'hard';
    case Soft = 'soft';
    case Unknown = 'unknown';
}
