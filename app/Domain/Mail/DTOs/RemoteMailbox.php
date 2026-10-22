<?php

namespace App\Domain\Mail\DTOs;

readonly class RemoteMailbox
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<RemoteSenderIdentity>  $senderIdentities
     */
    public function __construct(
        public string $providerMailboxId,
        public string $emailAddress,
        public ?string $displayName = null,
        public array $metadata = [],
        public array $senderIdentities = [],
    ) {}
}
