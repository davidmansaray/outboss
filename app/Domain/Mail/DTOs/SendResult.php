<?php

namespace App\Domain\Mail\DTOs;

readonly class SendResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $remoteMessageId,
        public ?string $remoteThreadId = null,
        public ?string $internetMessageId = null,
        public ?string $providerDraftId = null,
        public ?string $providerRequestId = null,
        public string $phase = 'sent',
        public array $metadata = [],
    ) {}

    public function isProviderSubmissionOnly(): bool
    {
        return $this->phase === 'send_submitted';
    }
}
