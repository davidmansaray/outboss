<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\DTOs\SendReconciliationResult;
use App\Domain\Mail\DTOs\UnknownSendReference;
use App\Models\Mailbox;

/** Safe compatibility result for adapters without a Sent search endpoint. */
trait ProviderUnknownSendReconciliation
{
    public function reconcileUnknownSend(Mailbox $mailbox, UnknownSendReference $reference): SendReconciliationResult
    {
        return SendReconciliationResult::manualReview(
            code: 'provider_reconciliation_unavailable',
            message: 'The provider did not expose a safe Sent search; confirm before retrying.',
        );
    }
}
