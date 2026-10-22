<?php

namespace App\Domain\Objectives\Enums;

enum ObjectiveTargetKind: string
{
    case Count = 'count';
    case Value = 'value';
}
