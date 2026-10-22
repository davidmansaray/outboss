<?php

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\DTOs\ResearchExtractRequest;
use App\Domain\Ai\DTOs\ResearchResult;
use App\Domain\Ai\DTOs\ResearchSearchRequest;

interface ResearchProvider
{
    public function search(string $secret, ResearchSearchRequest $request): ResearchResult;

    public function extract(string $secret, ResearchExtractRequest $request): ResearchResult;
}
