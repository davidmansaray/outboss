<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\Contracts\AiProviderAdapter;
use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResult;
use App\Domain\Ai\DTOs\ProviderTestResult;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Exceptions\SafeProviderException;
use App\Domain\Ai\Services\ProviderErrorRedactor;
use App\Domain\Ai\Support\UsdMicros;
use App\Models\AiProviderOperation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final class OpenRouterAdapter implements AiProviderAdapter, LlmProvider
{
    public function __construct(private readonly ProviderErrorRedactor $redactor) {}

    public function test(string $secret): ProviderTestResult
    {
        try {
            $metadata = $this->client($secret, 15)->get('/key')->throw();
        } catch (Throwable $exception) {
            throw $this->redactor->from($exception, false, AiProviderName::OpenRouter);
        }

        try {
            // The probe deliberately uses a provider-compatible minimum rather
            // than the deprecated max_tokens/one-token combination.
            $response = $this->client($secret, 30)->post('/chat/completions', [
                'model' => 'openai/gpt-5-mini',
                'messages' => [['role' => 'user', 'content' => 'Reply with a JSON object containing {"result":"OK"}.']],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'connection_test',
                        'strict' => true,
                        'schema' => self::connectionTestSchema(),
                    ],
                ],
                'max_completion_tokens' => 16,
                'stream' => false,
                'provider' => $this->providerPolicy(),
            ])->throw();

            $usage = $this->usage($response->json('usage', []));
            $rawCost = $this->rawDecimal($response->json('usage.cost'));
            $remaining = $metadata->json('data.limit_remaining');
            $balanceSummary = is_numeric($remaining)
                ? '$'.number_format((float) $remaining, 2).' remaining'
                : null;

            return new ProviderTestResult(
                nativeUnits: max(1, $usage['total_tokens']),
                nativeUnit: 'tokens',
                costMicros: $rawCost === null ? 10_000 : UsdMicros::ceilMicros($rawCost),
                costBasis: $rawCost === null ? 'upper_bound_estimate' : 'provider_exact',
                providerRequestId: $this->requestId($response),
                providerGenerationId: $this->generationId($response),
                balanceSummary: $balanceSummary,
                rawProviderCost: $rawCost,
                evidenceSource: $rawCost === null ? 'formula_estimate' : 'response',
                providerMetadata: $this->safeUsageMetadata(
                    $usage,
                    $response->json('model'),
                    $response->json('openrouter_metadata'),
                ),
                providerResponseId: $this->responseId($response),
            );
        } catch (SafeProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->redactor->from($exception, true, AiProviderName::OpenRouter);
        }
    }

    public function reconcile(string $secret, AiProviderOperation $operation): ?ProviderTestResult
    {
        if ($operation->provider_generation_id === null) {
            return null;
        }

        try {
            $response = $this->client($secret, 15)
                ->get('/generation', ['id' => $operation->provider_generation_id])
                ->throw();
            $data = $response->json('data', []);
            if (! is_array($data)) {
                return null;
            }

            $usage = $this->generationUsage($data);
            $rawCost = $this->rawDecimal(
                $data['total_cost'] ?? $data['cost'] ?? data_get($data, 'usage.cost'),
            );
            if ($rawCost === null) {
                return null;
            }

            $requestId = is_string($data['request_id'] ?? null)
                ? $data['request_id']
                : $operation->provider_request_id;
            $generationId = is_string($data['generation_id'] ?? null)
                ? $data['generation_id']
                : $operation->provider_generation_id;

            return new ProviderTestResult(
                nativeUnits: max(1, $usage['total_tokens']),
                nativeUnit: 'tokens',
                costMicros: UsdMicros::ceilMicros($rawCost),
                costBasis: 'provider_exact',
                providerRequestId: $requestId,
                providerGenerationId: $generationId,
                rawProviderCost: $rawCost,
                evidenceSource: 'generation_lookup',
                providerMetadata: $this->safeUsageMetadata($usage, $data['model'] ?? null, $data),
            );
        } catch (Throwable $exception) {
            throw $this->redactor->from($exception, true, AiProviderName::OpenRouter);
        }
    }

    public function generate(string $secret, LlmRequest $request): LlmResult
    {
        $models = config('ai.models', []);
        $model = is_array($models) ? ($models[$request->modelId] ?? null) : null;
        if (! is_array($model) || $request->maximumOutputTokens < 1) {
            throw new InvalidArgumentException('The LLM request is outside the curated policy.');
        }

        $schema = $request->responseSchema ?? self::defaultSchema();
        $schemaName = $request->responseSchemaName ?? 'structured_result';
        $this->assertClosedSchema($schema);

        try {
            $response = $this->client($secret, 60)->post('/chat/completions', [
                'model' => $request->modelId,
                'messages' => [['role' => 'user', 'content' => $request->content]],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $schemaName,
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
                'max_completion_tokens' => $request->maximumOutputTokens,
                'stream' => false,
                'provider' => $this->providerPolicy(),
            ])->throw();

            $content = $response->json('choices.0.message.content');
            $rawCost = $this->rawDecimal($response->json('usage.cost'));
            $usage = $this->usage($response->json('usage', []));
            if (! is_string($content)) {
                throw new SafeProviderException(
                    'output_invalid',
                    'The provider response did not include a valid structured result.',
                    true,
                );
            }
            if ($rawCost === null) {
                throw new SafeProviderException(
                    'paid_result_lost',
                    'The provider returned a result without authoritative cost evidence.',
                    true,
                );
            }

            $providerResponseId = $this->responseId($response);

            return new LlmResult(
                content: $content,
                inputTokens: $usage['input_tokens'],
                outputTokens: $usage['output_tokens'],
                reasoningTokens: $usage['reasoning_tokens'],
                cacheTokens: $usage['cache_tokens'],
                costMicros: UsdMicros::ceilMicros($rawCost),
                costBasis: 'provider_exact',
                providerRequestId: $this->requestId($response),
                providerGenerationId: $this->generationId($response),
                rawProviderCost: $rawCost,
                providerResponseId: $providerResponseId,
                totalTokens: $usage['total_tokens'],
                providerMetadata: $this->safeUsageMetadata(
                    $usage,
                    $response->json('model'),
                    $response->json('openrouter_metadata'),
                ),
            );
        } catch (SafeProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->redactor->from($exception, true, AiProviderName::OpenRouter);
        }
    }

    /** @return array<string, mixed> */
    private function providerPolicy(): array
    {
        return [
            'allow_fallbacks' => false,
            'require_parameters' => true,
            'data_collection' => 'deny',
            'zdr' => true,
        ];
    }

    private function client(string $secret, int $timeout): PendingRequest
    {
        return Http::baseUrl((string) config('ai.providers.openrouter.base_url'))
            ->withToken($secret)
            ->withHeaders(['X-OpenRouter-Metadata' => 'enabled'])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout($timeout)
            ->withoutRedirecting();
    }

    /** @return array{input_tokens:int,output_tokens:int,reasoning_tokens:int,cache_tokens:int,total_tokens:int} */
    private function usage(mixed $usage): array
    {
        $usage = is_array($usage) ? $usage : [];
        $input = $this->nonNegativeInt($usage['prompt_tokens'] ?? 0);
        $output = $this->nonNegativeInt($usage['completion_tokens'] ?? 0);
        $reasoning = $this->nonNegativeInt(
            data_get($usage, 'completion_tokens_details.reasoning_tokens')
                ?? ($usage['reasoning_tokens'] ?? 0),
        );
        $cache = $this->nonNegativeInt(
            data_get($usage, 'prompt_tokens_details.cached_tokens')
                ?? ($usage['cache_tokens'] ?? 0),
        );
        $total = $this->nonNegativeInt($usage['total_tokens'] ?? ($input + $output));

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning,
            'cache_tokens' => $cache,
            'total_tokens' => max($total, $input + $output),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{input_tokens:int,output_tokens:int,reasoning_tokens:int,cache_tokens:int,total_tokens:int}
     */
    private function generationUsage(array $data): array
    {
        $usage = is_array($data['usage'] ?? null)
            ? $data['usage']
            : [
                'prompt_tokens' => $data['tokens_prompt'] ?? $data['native_tokens_prompt'] ?? 0,
                'completion_tokens' => $data['tokens_completion'] ?? $data['native_tokens_completion'] ?? 0,
                'reasoning_tokens' => $data['native_tokens_reasoning'] ?? 0,
                'cache_tokens' => $data['native_tokens_cached'] ?? 0,
                'total_tokens' => ($data['tokens_prompt'] ?? $data['native_tokens_prompt'] ?? 0)
                    + ($data['tokens_completion'] ?? $data['native_tokens_completion'] ?? 0),
            ];

        return $this->usage($usage);
    }

    private function rawDecimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            // JSON decoding a numeric provider value necessarily passes
            // through a float; serialize it with enough precision, then use
            // exact decimal arithmetic for normalization.
            return sprintf('%.15F', $value);
        }
        if (is_string($value) && preg_match('/^\d+(?:\.\d+)?$/', trim($value)) === 1) {
            return trim($value);
        }

        return null;
    }

    private function requestId(mixed $response): ?string
    {
        $value = $response->header('X-Request-Id') ?? $response->header('x-request-id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function generationId(mixed $response): ?string
    {
        $value = $response->header('X-Generation-Id') ?? $response->header('x-generation-id');
        if (is_string($value) && $value !== '') {
            return $value;
        }

        // Older OpenRouter-compatible fixtures did not include the header. A
        // response-id fallback preserves replay/reconciliation compatibility;
        // current contract responses always use X-Generation-Id.
        return $this->responseId($response);
    }

    private function responseId(mixed $response): ?string
    {
        $value = $response->json('id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array{input_tokens:int,output_tokens:int,reasoning_tokens:int,cache_tokens:int,total_tokens:int}  $usage
     * @return array<string, mixed>
     */
    private function safeUsageMetadata(array $usage, mixed $model, mixed $routing = null): array
    {
        $metadata = [
            'model' => is_string($model) ? mb_substr($model, 0, 160) : null,
            'input_tokens' => $usage['input_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'reasoning_tokens' => $usage['reasoning_tokens'],
            'cache_tokens' => $usage['cache_tokens'],
            'total_tokens' => $usage['total_tokens'],
        ];
        $safeRouting = $this->safeRoutingMetadata($routing);
        if ($safeRouting !== []) {
            $metadata['openrouter_metadata'] = $safeRouting;
        }

        return $metadata;
    }

    /** @return array<string, mixed> */
    private function safeRoutingMetadata(mixed $routing): array
    {
        if (! is_array($routing)) {
            return [];
        }

        $safe = [];
        foreach (['attempt', 'generation_time', 'is_byok', 'region', 'requested', 'strategy', 'summary', 'provider_name', 'router', 'service_tier'] as $key) {
            $value = $routing[$key] ?? null;
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $safe[$key] = $value;
            } elseif (is_string($value)) {
                $safe[$key] = mb_substr($value, 0, 160);
            }
        }
        if (is_array($routing['endpoints'] ?? null)) {
            $endpoints = [];
            foreach (['available', 'selected'] as $group) {
                if (! is_array($routing['endpoints'][$group] ?? null)) {
                    continue;
                }
                foreach (array_slice($routing['endpoints'][$group], 0, 16) as $endpoint) {
                    if (! is_array($endpoint)) {
                        continue;
                    }
                    $safeEndpoint = [];
                    foreach (['model', 'provider', 'selected'] as $key) {
                        $value = $endpoint[$key] ?? null;
                        if (is_bool($value) || is_int($value)) {
                            $safeEndpoint[$key] = $value;
                        } elseif (is_string($value)) {
                            $safeEndpoint[$key] = mb_substr($value, 0, 160);
                        }
                    }
                    if ($safeEndpoint !== []) {
                        $endpoints[] = $safeEndpoint;
                    }
                }
            }
            if ($endpoints !== []) {
                $safe['endpoints'] = $endpoints;
            }
        }

        return $safe;
    }

    /** @param array<string, mixed> $schema */
    private function assertClosedSchema(array $schema): void
    {
        if (($schema['type'] ?? null) === 'object') {
            if (($schema['additionalProperties'] ?? null) !== false) {
                throw new InvalidArgumentException('Structured output schemas must be closed objects.');
            }
            foreach (($schema['properties'] ?? []) as $property) {
                if (is_array($property)) {
                    $this->assertClosedSchema($property);
                }
            }
        }
        if (($schema['type'] ?? null) === 'array' && is_array($schema['items'] ?? null)) {
            $this->assertClosedSchema($schema['items']);
        }
    }

    /** @return array<string, mixed> */
    private static function defaultSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['result' => ['type' => 'string']],
            'required' => ['result'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function connectionTestSchema(): array
    {
        return self::defaultSchema();
    }

    private function nonNegativeInt(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
