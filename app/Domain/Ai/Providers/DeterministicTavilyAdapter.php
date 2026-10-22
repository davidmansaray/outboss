<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\Contracts\AiProviderAdapter;
use App\Domain\Ai\Contracts\ResearchProvider;
use App\Domain\Ai\DTOs\ProviderTestResult;
use App\Domain\Ai\DTOs\ResearchExtractRequest;
use App\Domain\Ai\DTOs\ResearchResult;
use App\Domain\Ai\DTOs\ResearchSearchRequest;
use App\Models\AiProviderOperation;
use RuntimeException;

/** Deterministic, network-free adapter available only to the S2 browser-test app. */
final class DeterministicTavilyAdapter implements AiProviderAdapter, ResearchProvider
{
    public const FIXTURE_ID = 's2-browser-v1';

    public function test(string $secret): ProviderTestResult
    {
        $this->assertFixture($secret);

        return new ProviderTestResult(
            nativeUnits: 1,
            nativeUnit: 'credits',
            costMicros: 8000,
            costBasis: 'provider_exact',
            providerRequestId: 'fake-tavily-test-v1',
            balanceSummary: 'Deterministic browser fixture',
            evidenceSource: 'response',
            providerMetadata: ['fixture_id' => self::FIXTURE_ID],
        );
    }

    public function search(string $secret, ResearchSearchRequest $request): ResearchResult
    {
        $this->assertFixture($secret);

        return new ResearchResult(
            results: [[
                'url' => 'https://example.com/research/company-update',
                'title' => 'Company update',
                'content' => 'A current company update corroborates the public business model.',
            ]],
            credits: 1,
            providerRequestId: 'fake-tavily-search-v1',
            reportedCredits: 1,
            providerMetadata: ['fixture_id' => self::FIXTURE_ID],
        );
    }

    public function extract(string $secret, ResearchExtractRequest $request): ResearchResult
    {
        $this->assertFixture($secret);
        $url = $request->urls[0] ?? 'https://example.com';

        return new ResearchResult(
            results: [[
                'url' => $url,
                'title' => 'Public company homepage',
                'raw_content' => 'The company describes B2B software, a founder-led motion, and evidence automation. A legacy statement uses broader positioning. Employee count is not published.',
                'mime_type' => 'text/html',
            ]],
            credits: 1,
            providerRequestId: 'fake-tavily-extract-v1',
            reportedCredits: 1,
            providerMetadata: ['fixture_id' => self::FIXTURE_ID],
        );
    }

    public function reconcile(string $secret, AiProviderOperation $operation): ?ProviderTestResult
    {
        $this->assertFixture($secret);

        return null;
    }

    private function assertFixture(string $secret): void
    {
        if (! hash_equals(self::FIXTURE_ID, $secret)) {
            throw new RuntimeException('The deterministic Tavily fixture is unavailable.');
        }
    }
}
