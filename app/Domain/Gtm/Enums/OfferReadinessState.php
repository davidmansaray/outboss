<?php

namespace App\Domain\Gtm\Enums;

enum OfferReadinessState: string
{
    case Incomplete = 'incomplete';
    case ReadyForReview = 'ready_for_review';
    case Approved = 'approved';
    case Blocked = 'blocked';
    case Expired = 'expired';
}
