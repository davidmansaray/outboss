<?php

namespace App\Domain\Objectives\Enums;

enum ExecutionActionEventType: string
{
    case Planned = 'planned';
    case Compiled = 'compiled';
    case Queued = 'queued';
    case Attempted = 'attempted';
    case ProviderAccepted = 'provider_accepted';
    case Uncertain = 'uncertain';
    case Failed = 'failed';
    case ConfirmedSent = 'confirmed_sent';
    case ReplyObserved = 'reply_observed';
    case Stopped = 'stopped';
}
