<?php

namespace App\Domain\Intelligence\Enums;

enum QualificationOutcome: string
{
    case Contact = 'contact';
    case Withhold = 'withhold';
    case ResearchFurther = 'research_further';
    case InsufficientEvidence = 'insufficient_evidence';
    case NeedsReview = 'needs_review';
}
