<?php

namespace App\Domain\Campaigns\Enums;

enum SequenceVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
