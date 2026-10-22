<?php

namespace App\Domain\Intelligence\Enums;

enum QualificationRubricState: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
