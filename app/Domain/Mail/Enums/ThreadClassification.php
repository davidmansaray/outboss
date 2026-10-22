<?php

namespace App\Domain\Mail\Enums;

enum ThreadClassification: string
{
    case Human = 'human';
    case OutOfOffice = 'out_of_office';
    case Bounce = 'bounce';
    case Unsubscribe = 'unsubscribe';
    case System = 'system';
    case Unknown = 'unknown';
}
