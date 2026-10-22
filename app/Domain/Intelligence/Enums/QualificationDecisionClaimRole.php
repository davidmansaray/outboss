<?php

namespace App\Domain\Intelligence\Enums;

enum QualificationDecisionClaimRole: string
{
    case Required = 'required';
    case Supporting = 'supporting';
    case Contradicting = 'contradicting';
    case Missing = 'missing';
    case Disqualifying = 'disqualifying';
}
