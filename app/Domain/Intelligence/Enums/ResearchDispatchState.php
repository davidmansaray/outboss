<?php

namespace App\Domain\Intelligence\Enums;

enum ResearchDispatchState: string
{
    case Pending = 'pending';
    case Leased = 'leased';
    case Published = 'published';
    case Retired = 'retired';
    case Failed = 'failed';
}
