<?php

namespace App\Domain\Objectives\Enums;

enum StrategyStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
