<?php

namespace App\Domain\Objectives\Enums;

enum ApprovalBatchState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case Invalidated = 'invalidated';
}
