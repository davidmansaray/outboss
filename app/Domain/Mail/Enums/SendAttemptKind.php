<?php

namespace App\Domain\Mail\Enums;

enum SendAttemptKind: string
{
    case Campaign = 'campaign';
    case Test = 'test';
    case Reply = 'reply';
    case Compose = 'compose';
}
