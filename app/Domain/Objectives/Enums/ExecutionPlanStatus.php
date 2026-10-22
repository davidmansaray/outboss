<?php

namespace App\Domain\Objectives\Enums;

enum ExecutionPlanStatus: string
{
    case Draft = 'draft';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
    case Compiled = 'compiled';
    case Executing = 'executing';
    case Completed = 'completed';
    case Partial = 'partial';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';
}
