<?php

namespace App\Domain\Intelligence\Enums;

enum ResearchSourceSnapshotState: string
{
    case Available = 'available';
    case Partial = 'partial';
    case Failed = 'failed';
    case Restricted = 'restricted';
    case Expired = 'expired';
    case Redacted = 'redacted';
}
