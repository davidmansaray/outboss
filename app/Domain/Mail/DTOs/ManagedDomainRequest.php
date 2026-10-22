<?php

namespace App\Domain\Mail\DTOs;

/** Input passed from the local managed-domain service to a provisioner. */
readonly class ManagedDomainRequest
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $domain,
        public string $managedInstanceKey = 'internal',
        public ?int $workspaceId = null,
        public array $metadata = [],
    ) {}
}
