<?php

namespace App\Domain\Integrations\Enums;

enum ExternalOperationAttemptKind: string
{
    case Execute = 'execute';
    case Reconcile = 'reconcile';
    case Poll = 'poll';
    case Download = 'download';
    case Cancel = 'cancel';
}
