<?php

namespace App\Domain\Mail\Contracts;

use App\Domain\Mail\DTOs\OutgoingMessage;
use App\Domain\Mail\DTOs\RemoteMailbox;
use App\Domain\Mail\DTOs\RemoteMessagePage;
use App\Domain\Mail\DTOs\RemoteMessageReference;
use App\Domain\Mail\DTOs\SendReconciliationResult;
use App\Domain\Mail\DTOs\SendResult;
use App\Domain\Mail\DTOs\UnknownSendReference;
use App\Models\Mailbox;
use App\Models\MailboxSyncState;
use App\Models\ProviderConnection;

/**
 * Runtime mail operations for a connected mailbox.
 *
 * Runtime mail access is deliberately separate from OAuth.  Managed
 * mailboxes authenticate with an account credential and therefore must not
 * be forced to implement an OAuth redirect or token refresh flow.
 */
interface MailTransport
{
    /** @return list<RemoteMailbox> */
    public function mailboxes(ProviderConnection $connection): array;

    public function syncFolder(
        Mailbox $mailbox,
        MailboxSyncState $state,
        int $pageSize = 100,
    ): RemoteMessagePage;

    public function sendNew(Mailbox $mailbox, OutgoingMessage $message): SendResult;

    public function sendReply(
        Mailbox $mailbox,
        RemoteMessageReference $replyTo,
        OutgoingMessage $message,
    ): SendResult;

    public function reconcileUnknownSend(
        Mailbox $mailbox,
        UnknownSendReference $reference,
    ): SendReconciliationResult;
}
