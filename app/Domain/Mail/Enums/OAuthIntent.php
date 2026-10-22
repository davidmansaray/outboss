<?php

namespace App\Domain\Mail\Enums;

enum OAuthIntent: string
{
    case ConnectMailbox = 'connect_mailbox';
    case ReconnectMailbox = 'reconnect_mailbox';
}
