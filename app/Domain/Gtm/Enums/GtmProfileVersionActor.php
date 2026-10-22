<?php

namespace App\Domain\Gtm\Enums;

enum GtmProfileVersionActor: string
{
    case Owner = 'owner';
    case SystemResearch = 'system_research';
    case SystemSeed = 'system_seed';
}
