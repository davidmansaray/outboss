<?php

namespace App\Domain\Campaigns\Enums;

enum ImportRowIssue: string
{
    case Ready = 'ready';
    case Invalid = 'invalid';
    case Duplicate = 'duplicate';
    case Suppressed = 'suppressed';
}
