<?php

namespace App\Domain\Mail\DTOs;

use DateTimeInterface;

/** Stable evidence used to search a provider after an ambiguous submission. */
readonly class UnknownSendReference
{
    public function __construct(
        public ?string $clientMessageMarker = null,
        public ?string $internetMessageId = null,
        public ?string $subject = null,
        public ?DateTimeInterface $submittedAt = null,
        public ?string $providerRequestId = null,
        /**
         * Stalwart submission evidence is deliberately tri-state. `accepted`
         * is a provider-created EmailSubmission, `not_created` is an explicit
         * EmailSubmission/set failure after Email/set created a Sent copy, and
         * `unknown` requires reconciliation to query EmailSubmission. Null
         * retains compatibility with providers that do not expose this data.
         */
        public ?string $submissionState = null,
    ) {}
}
