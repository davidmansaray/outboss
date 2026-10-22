<?php

namespace App\Domain\Objectives\Enums;

enum GtmConversationState: string
{
    case Open = 'open';
    case NeedsReview = 'needs_review';
    case Qualified = 'qualified';
    case NotNow = 'not_now';
    case Closed = 'closed';
    case Converted = 'converted';
}
