<?php

namespace App\Domain\Integrations\Providers;

use App\Domain\Integrations\Contracts\CapabilityProvider;
use InvalidArgumentException;

class ProviderRegistry
{
    /** @var array<string, ProviderDefinition> */
    private array $definitions = [];

    /**
     * @param  array<string, CapabilityProvider|ProviderDefinition>  $providers
     */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $key => $provider) {
            if ($provider instanceof ProviderDefinition) {
                $this->definitions[$provider->key] = $provider;

                continue;
            }
            $this->register($provider, (string) $key);
        }
    }

    public function register(CapabilityProvider $provider, ?string $key = null, ?ProviderDefinition $definition = null): void
    {
        $providerKey = $key ?? $provider->providerKey();
        if ($providerKey !== $provider->providerKey()) {
            throw new InvalidArgumentException('The provider key does not match the adapter.');
        }
        if (isset($this->definitions[$providerKey])) {
            throw new InvalidArgumentException("Provider [{$providerKey}] is already registered.");
        }

        $config = config("integrations.providers.{$providerKey}", []);
        $this->definitions[$providerKey] = $definition ?? new ProviderDefinition(
            key: $providerKey,
            displayName: (string) ($config['display_name'] ?? $providerKey),
            capabilities: $provider->capabilities(),
            installed: (bool) ($config['installed'] ?? true),
            enabled: (bool) ($config['enabled'] ?? true),
            availabilityReason: isset($config['availability_reason']) ? (string) $config['availability_reason'] : null,
            adapter: $provider,
        );
    }

    public function for(string $provider): CapabilityProvider
    {
        $definition = $this->definition($provider);
        if (! $definition->available()) {
            throw new InvalidArgumentException(
                "Provider [{$provider}] is unavailable.".($definition->availabilityReason ? " ({$definition->availabilityReason})" : '')
            );
        }

        return $definition->adapter;
    }

    public function definition(string $provider): ProviderDefinition
    {
        return $this->definitions[$provider]
            ?? throw new InvalidArgumentException("Unknown integration provider [{$provider}].");
    }

    public function has(string $provider): bool
    {
        return isset($this->definitions[$provider]);
    }

    public function supports(string $provider, string $capability): bool
    {
        return $this->has($provider)
            && in_array($capability, $this->definitions[$provider]->capabilities, true);
    }

    /** @return list<ProviderDefinition> */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }

    /** @return list<ProviderDefinition> */
    public function forCapability(string $capability): array
    {
        return array_values(array_filter(
            $this->definitions,
            fn (ProviderDefinition $definition): bool => in_array($capability, $definition->capabilities, true),
        ));
    }
}
