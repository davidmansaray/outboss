<?php

namespace App\Domain\Objectives\Enums;

enum PolicyEvaluationResult: string
{
    case Allowed = 'allowed';
    case Blocked = 'blocked';
    case RequiresReview = 'requires_review';
}
