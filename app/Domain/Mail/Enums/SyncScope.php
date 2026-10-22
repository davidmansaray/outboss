<?php

namespace App\Domain\Mail\Enums;

enum SyncScope: string
{
    case Mailbox = 'mailbox';
    case Inbox = 'inbox';
    case Sent = 'sent';
}
