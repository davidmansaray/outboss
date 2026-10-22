<?php

namespace App\Domain\Prospecting\Enums;

enum CanonicalFieldDecision: string
{
    case Applied = 'applied';
    case KeptExisting = 'kept_existing';
    case Conflicted = 'conflicted';
    case ClearedByOwner = 'cleared_by_owner';
}
