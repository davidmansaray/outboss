<?php

namespace App\Domain\Mail\DTOs;

/** Input passed from the local managed-mailbox service to a provisioner. */
readonly class ManagedMailboxRequest
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $localPart,
        public string $domain,
        public string $remoteDomainId,
        public ?string $displayName = null,
        public ?string $password = null,
        public string $managedInstanceKey = 'internal',
        public ?int $workspaceId = null,
        public array $metadata = [],
    ) {}
}
