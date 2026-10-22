<?php

namespace App\Domain\Prospecting\Enums;

enum ObservationEntityType: string
{
    case Account = 'account';
    case Person = 'person';
}
