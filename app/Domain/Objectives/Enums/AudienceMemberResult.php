<?php

namespace App\Domain\Objectives\Enums;

enum AudienceMemberResult: string
{
    case Included = 'included';
    case Excluded = 'excluded';
    case Uncertain = 'uncertain';
    case Suppressed = 'suppressed';
}
