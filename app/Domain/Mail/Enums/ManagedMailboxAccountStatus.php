<?php

namespace App\Domain\Mail\Enums;

enum ManagedMailboxAccountStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case RotationRequired = 'rotation_required';
    case Failed = 'failed';
    case Deleted = 'deleted';
}
