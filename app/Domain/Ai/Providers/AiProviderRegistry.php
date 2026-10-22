<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\Contracts\AiProviderAdapter;
use App\Domain\Ai\Enums\AiProviderName;
use InvalidArgumentException;

class AiProviderRegistry
{
    /** @param array<string, AiProviderAdapter> $adapters */
    public function __construct(private readonly array $adapters) {}

    public function for(AiProviderName $provider): AiProviderAdapter
    {
        return $this->adapters[$provider->value]
            ?? throw new InvalidArgumentException("Unsupported AI provider [{$provider->value}].");
    }
}
