<?php

namespace App\Domain\Integrations\Providers;

use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Providers\AiProviderRegistry;
use App\Domain\Integrations\Contracts\CapabilityProvider;
use App\Domain\Integrations\Contracts\CapabilityRequest;
use App\Domain\Integrations\Contracts\CapabilityResult;
use App\Domain\Integrations\DTOs\CapabilityDefinition;
use App\Domain\Integrations\Exceptions\CapabilityUnavailable;
use App\Domain\Integrations\Support\CapabilityCatalogue;
use App\Models\ExternalOperation;

/**
 * Read-only catalogue bridge for the existing AF-01 OpenRouter/Tavily plane.
 * It intentionally does not execute or duplicate AI operations.
 */
final class AiCapabilityBridge implements CapabilityProvider
{
    public function __construct(private readonly ?AiProviderRegistry $aiProviders = null) {}

    public function providerKey(): string
    {
        return 'ai';
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['ai.synthesize'];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function execute(CapabilityRequest $request): CapabilityResult
    {
        throw new CapabilityUnavailable($request->capability, 'read_only_ai_bridge');
    }

    public function reconcile(CapabilityRequest $request, ExternalOperation $operation): ?CapabilityResult
    {
        throw new CapabilityUnavailable($request->capability, 'read_only_ai_bridge');
    }

    /** @return list<array<string, mixed>> */
    public function providerSummaries(): array
    {
        return [
            [
                'provider' => 'openrouter',
                'capabilities' => ['ai.synthesize'],
                'tasks' => array_keys(config('ai.tasks', [])),
                'availability' => 'af01',
                'adapter_registered' => $this->adapterRegistered(AiProviderName::OpenRouter),
            ],
            [
                'provider' => 'tavily',
                'capabilities' => ['web.search', 'website.fetch'],
                'availability' => 'af01',
                'adapter_registered' => $this->adapterRegistered(AiProviderName::Tavily),
            ],
        ];
    }

    private function adapterRegistered(AiProviderName $provider): bool
    {
        if ($this->aiProviders === null) {
            return false;
        }

        try {
            $this->aiProviders->for($provider);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** @return list<CapabilityDefinition> */
    public function definitions(): array
    {
        $catalogue = app(CapabilityCatalogue::class);

        return array_values(array_filter(
            $catalogue->all(),
            fn (CapabilityDefinition $definition): bool => $this->supports($definition->name),
        ));
    }
}
