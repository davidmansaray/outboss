<?php

namespace App\Domain\Integrations\DTOs;

final readonly class ProviderRegistrationReference
{
    public function __construct(
        public int $id,
        public int $workspaceId,
        public string $provider,
        public string $capability,
        public string $adapterVersion,
        public int $capabilitySchemaVersion,
        public int $pricingVersion,
        public int $policyVersion,
        public string $codeDefinitionHash,
        public bool $enabled = true,
    ) {}
}
