<?php

namespace App\Domain\Mail\DTOs;

/** Remote account identity and credential material returned once at creation. */
readonly class ProvisionedMailbox
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $remoteAccountId,
        public string $emailAddress,
        public ?string $username = null,
        public ?string $password = null,
        public array $metadata = [],
    ) {}
}
