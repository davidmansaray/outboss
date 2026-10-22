<?php

namespace App\Domain\Mail\Exceptions;

use RuntimeException;

class SenderIdentityUnavailable extends RuntimeException
{
    public const USER_MESSAGE = 'No active sender identity is available. Reconnect this inbox to refresh its From addresses.';

    public function __construct()
    {
        parent::__construct('mailbox_sender_identity_unavailable');
    }
}
