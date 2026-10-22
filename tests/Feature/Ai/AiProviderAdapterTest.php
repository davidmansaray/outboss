<?php

use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\ResearchExtractRequest;
use App\Domain\Ai\DTOs\ResearchSearchRequest;
use App\Domain\Ai\Providers\OpenRouterAdapter;
use App\Domain\Ai\Providers\TavilyAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

it('generates with a curated OpenRouter model and mandatory privacy controls', function () {
    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-contract',
            'choices' => [['message' => ['content' => 'Structured result']]],
            'usage' => [
                'prompt_tokens' => 120,
                'completion_tokens' => 30,
                'completion_tokens_details' => ['reasoning_tokens' => 10],
                'prompt_tokens_details' => ['cached_tokens' => 5],
                'total_tokens' => 150,
                'cost' => '0.0012501',
            ],
            'model' => 'openai/gpt-5-mini',
            'openrouter_metadata' => [
                'provider_name' => 'OpenAI',
                'requested' => 'openai/gpt-5-mini',
                'endpoints' => ['available' => [['provider' => 'OpenAI', 'selected' => true]]],
            ],
        ], 200, ['x-request-id' => 'req-contract', 'X-Generation-Id' => 'gen-contract']),
    ]);

    $result = app(OpenRouterAdapter::class)->generate(
        'sk-or-contract-secret',
        new LlmRequest('openai/gpt-5-mini', 'Private request content', 200),
    );

    expect($result->content)->toBe('Structured result')
        ->and($result->costMicros)->toBe(1_251)
        ->and($result->rawProviderCost)->toBe('0.0012501')
        ->and($result->providerRequestId)->toBe('req-contract')
        ->and($result->providerGenerationId)->toBe('gen-contract')
        ->and($result->providerResponseId)->toBe('chatcmpl-contract')
        ->and($result->reasoningTokens)->toBe(10)
        ->and($result->cacheTokens)->toBe(5)
        ->and($result->totalTokens)->toBe(150)
        ->and($result->providerMetadata['openrouter_metadata']['provider_name'])->toBe('OpenAI');
    Http::assertSent(fn (Request $request) => $request['model'] === 'openai/gpt-5-mini'
        && $request['stream'] === false
        && $request['max_completion_tokens'] === 200
        && ! isset($request['max_tokens'])
        && $request['response_format']['type'] === 'json_schema'
        && $request['response_format']['json_schema']['strict'] === true
        && $request['response_format']['json_schema']['schema']['additionalProperties'] === false
        && $request['provider']['allow_fallbacks'] === false
        && $request['provider']['require_parameters'] === true
        && $request['provider']['data_collection'] === 'deny'
        && $request['provider']['zdr'] === true
        && $request->hasHeader('X-OpenRouter-Metadata', 'enabled')
        && ! isset($request['plugins'])
        && ! isset($request['tools']));
});

it('bounds Tavily Search and Extract and calculates native credits deterministically', function () {
    Http::fake([
        'https://api.tavily.com/search' => Http::response([
            'request_id' => 'tv-search',
            'results' => [['url' => 'https://example.com', 'title' => 'Example']],
            'usage' => ['credits' => 2],
            'response_time' => 0.25,
        ]),
        'https://api.tavily.com/extract' => Http::response([
            'request_id' => 'tv-extract',
            'results' => [['url' => 'https://example.com', 'raw_content' => 'Content']],
            'failed_results' => [['url' => 'https://unavailable.example', 'status' => 503, 'error' => 'upstream timeout']],
            'usage' => ['credits' => 4],
            'response_time' => 1.25,
        ]),
    ]);
    $adapter = app(TavilyAdapter::class);

    $search = $adapter->search('tvly-contract-secret', new ResearchSearchRequest('public company facts', 'advanced', 3));
    $extract = $adapter->extract('tvly-contract-secret', new ResearchExtractRequest([
        'https://example.com/1',
        'https://example.com/2',
        'https://example.com/3',
        'https://example.com/4',
        'https://example.com/5',
        'https://example.com/6',
    ], 'advanced'));

    expect($search->credits)->toBe(2)
        ->and($extract->credits)->toBe(4)
        ->and($search->providerRequestId)->toBe('tv-search')
        ->and($extract->providerRequestId)->toBe('tv-extract')
        ->and($search->reportedCredits)->toBe(2)
        ->and($extract->reportedCredits)->toBe(4)
        ->and($extract->failedResults)->toHaveCount(1)
        ->and($extract->failedResults[0]['status'])->toBe(503);
    Http::assertSent(fn (Request $request) => ! str_ends_with($request->url(), '/search')
        || ($request['include_answer'] === false
            && $request['include_raw_content'] === false
            && $request['include_usage'] === true
            && $request['max_results'] === 3
            && $request->hasHeader('X-Project-ID', 'outboss-ai')));

    expect(fn () => $adapter->extract('tvly-contract-secret', new ResearchExtractRequest(array_fill(0, 21, 'https://example.com'))))
        ->toThrow(InvalidArgumentException::class);
    Http::assertSentCount(2);
});
