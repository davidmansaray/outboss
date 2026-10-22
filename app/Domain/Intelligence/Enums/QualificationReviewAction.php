<?php

namespace App\Domain\Intelligence\Enums;

enum QualificationReviewAction: string
{
    case Accept = 'accept';
    case Correct = 'correct';
    case Reject = 'reject';
    case RequestResearch = 'request_research';
    case Defect = 'defect';
}
