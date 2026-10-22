<?php

namespace App\Domain\Objectives\Enums;

enum SegmentStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
