<?php

namespace App\Domain\Objectives\Enums;

enum ExecutionPlanActionState: string
{
    case Planned = 'planned';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
    case Compiled = 'compiled';
    case Queued = 'queued';
    case Attempted = 'attempted';
    case Uncertain = 'uncertain';
    case ProviderAccepted = 'provider_accepted';
    case Sent = 'sent';
    case Ineligible = 'ineligible';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';
}
