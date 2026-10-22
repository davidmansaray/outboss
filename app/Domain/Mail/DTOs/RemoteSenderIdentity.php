<?php

namespace App\Domain\Mail\DTOs;

/**
 * A provider-neutral, sanitized From identity returned by a mail provider.
 */
readonly class RemoteSenderIdentity
{
    public function __construct(
        public string $key,
        public string $emailAddress,
        public ?string $displayName = null,
        public ?string $remoteId = null,
        public ?string $mode = null,
        public bool $active = true,
    ) {}
}
