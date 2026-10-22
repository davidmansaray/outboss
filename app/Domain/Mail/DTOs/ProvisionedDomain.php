<?php

namespace App\Domain\Mail\DTOs;

/** Remote domain identity and public DNS guidance returned by a provisioner. */
readonly class ProvisionedDomain
{
    /**
     * @param  array<string, mixed>  $dnsRecords
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $remoteDomainId,
        public string $domain,
        public array $dnsRecords = [],
        public array $metadata = [],
    ) {}
}
