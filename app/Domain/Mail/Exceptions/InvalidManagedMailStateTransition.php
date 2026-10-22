<?php

namespace App\Domain\Mail\Exceptions;

use App\Domain\Mail\Enums\ManagedMailboxAccountStatus;
use App\Domain\Mail\Enums\ManagedMailDomainStatus;
use LogicException;

final class InvalidManagedMailStateTransition extends LogicException
{
    public static function domain(
        ManagedMailDomainStatus $from,
        ManagedMailDomainStatus $to,
    ): self {
        return new self("Managed mail domain cannot transition from [{$from->value}] to [{$to->value}].");
    }

    public static function mailbox(
        ManagedMailboxAccountStatus $from,
        ManagedMailboxAccountStatus $to,
    ): self {
        return new self("Managed mailbox account cannot transition from [{$from->value}] to [{$to->value}].");
    }
}
