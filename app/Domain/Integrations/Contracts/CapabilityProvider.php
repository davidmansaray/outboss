<?php

namespace App\Domain\Integrations\Contracts;

use App\Models\ExternalOperation;

interface CapabilityProvider
{
    public function providerKey(): string;

    /** @return list<string> */
    public function capabilities(): array;

    public function supports(string $capability): bool;

    public function execute(CapabilityRequest $request): CapabilityResult;

    public function reconcile(CapabilityRequest $request, ExternalOperation $operation): ?CapabilityResult;
}
