<?php

namespace App\Domain\Mail\Enums;

enum ManagedMailDomainStatus: string
{
    case Draft = 'draft';
    case Provisioning = 'provisioning';
    case PendingDns = 'pending_dns';
    case Verified = 'verified';
    case Active = 'active';
    case Suspended = 'suspended';
    case Failed = 'failed';
}
