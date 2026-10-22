<?php

namespace App\Domain\Intelligence\Enums;

enum ResearchClaimEventAction: string
{
    case Confirm = 'confirm';
    case Correct = 'correct';
    case Reject = 'reject';
    case MarkStale = 'mark_stale';
    case Restore = 'restore';
    case PolicyRedact = 'policy_redact';
}
