<?php

namespace App\Domain\Objectives\Enums;

enum OutreachPolicyStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
}
