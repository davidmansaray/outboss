<?php

namespace App\Domain\Objectives\Enums;

enum ApprovalEventType: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case Invalidated = 'invalidated';
}
