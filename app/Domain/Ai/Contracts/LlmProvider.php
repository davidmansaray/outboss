<?php

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResult;

interface LlmProvider
{
    public function generate(string $secret, LlmRequest $request): LlmResult;
}
