<?php

namespace App\Domain\Mail\Enums;

enum SendResolution: string
{
    case ConfirmedSent = 'confirmed_sent';
    case ProvenNotSent = 'proven_not_sent';
}
