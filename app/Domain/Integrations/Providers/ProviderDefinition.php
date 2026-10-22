<?php

namespace App\Domain\Integrations\Providers;

use App\Domain\Integrations\Contracts\CapabilityProvider;

final readonly class ProviderDefinition
{
    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public string $key,
        public string $displayName,
        public array $capabilities,
        public bool $installed = true,
        public bool $enabled = true,
        public ?string $availabilityReason = null,
        public ?CapabilityProvider $adapter = null,
    ) {}

    public function available(): bool
    {
        return $this->installed && $this->enabled && $this->adapter !== null;
    }
}
