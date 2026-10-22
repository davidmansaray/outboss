<?php

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\DTOs\ProviderTestResult;
use App\Models\AiProviderOperation;

interface AiProviderAdapter
{
    public function test(string $secret): ProviderTestResult;

    public function reconcile(string $secret, AiProviderOperation $operation): ?ProviderTestResult;
}
