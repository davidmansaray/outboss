<?php

namespace App\Domain\Intelligence\Enums;

enum ReasonToContactClaimRole: string
{
    case Basis = 'basis';
    case Timing = 'timing';
    case Offer = 'offer';
    case Contradicting = 'contradicting';
}
