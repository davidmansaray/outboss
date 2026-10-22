<?php

namespace App\Domain\Intelligence\Enums;

enum QualificationReviewState: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Corrected = 'corrected';
    case Rejected = 'rejected';
    case ResearchRequested = 'research_requested';
}
