<?php

namespace App\Domain\Conversions\Enums;

enum ConversionConnectionStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
