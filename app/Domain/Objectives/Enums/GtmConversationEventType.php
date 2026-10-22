<?php

namespace App\Domain\Objectives\Enums;

enum GtmConversationEventType: string
{
    case ReplyObserved = 'reply_observed';
    case Classified = 'classified';
    case ClassificationCorrected = 'classification_corrected';
    case Assigned = 'assigned';
    case NextActionSet = 'next_action_set';
    case Stopped = 'stopped';
    case Escalated = 'escalated';
    case ConversionObserved = 'conversion_observed';
    case Closed = 'closed';
}
