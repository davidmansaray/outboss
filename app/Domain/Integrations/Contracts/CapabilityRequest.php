<?php

namespace App\Domain\Integrations\Contracts;

/**
 * Provider adapters receive only this bounded, validated envelope. Provider
 * credentials and raw source payloads never belong in it.
 */
class CapabilityRequest
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $policySnapshot
     */
    public function __construct(
        public readonly string $capability,
        public readonly array $input,
        public readonly array $metadata = [],
        public readonly ?string $providerKey = null,
        public readonly ?string $sourceRunId = null,
        public readonly int $policyVersion = 1,
        public readonly array $policySnapshot = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'capability' => $this->capability,
            'input' => $this->input,
            'metadata' => $this->metadata,
            'provider_key' => $this->providerKey,
            'source_run_id' => $this->sourceRunId,
            'policy_version' => $this->policyVersion,
            'policy_snapshot' => $this->policySnapshot,
        ];
    }
}
