<?php

namespace App\Domain\Intelligence\Enums;

enum ResearchClaimStatus: string
{
    case Supported = 'supported';
    case Inferred = 'inferred';
    case Conflicting = 'conflicting';
    case Stale = 'stale';
    case Unsupported = 'unsupported';
    case OwnerConfirmed = 'owner_confirmed';
    case Rejected = 'rejected';
}
