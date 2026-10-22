<?php

namespace App\Domain\Conversions\Enums;

enum ConversionCredentialStatus: string
{
    case Active = 'active';
    case Retiring = 'retiring';
    case Wiped = 'wiped';
}
