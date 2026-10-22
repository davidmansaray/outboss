<?php

namespace App\Domain\Intelligence\Enums;

enum ResearchClaimEvidenceEdgeType: string
{
    case Supports = 'supports';
    case Contradicts = 'contradicts';
}
