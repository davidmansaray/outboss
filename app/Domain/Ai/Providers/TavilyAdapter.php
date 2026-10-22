<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\Contracts\AiProviderAdapter;
use App\Domain\Ai\Contracts\ResearchProvider;
use App\Domain\Ai\DTOs\ProviderTestResult;
use App\Domain\Ai\DTOs\ResearchExtractRequest;
use App\Domain\Ai\DTOs\ResearchResult;
use App\Domain\Ai\DTOs\ResearchSearchRequest;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Exceptions\SafeProviderException;
use App\Domain\Ai\Services\ProviderErrorRedactor;
use App\Models\AiProviderOperation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final class TavilyAdapter implements AiProviderAdapter, ResearchProvider
{
    public function __construct(private readonly ProviderErrorRedactor $redactor) {}

    public function test(string $secret): ProviderTestResult
    {
        try {
            $metadata = $this->usageMetadata($secret);
        } catch (Throwable $exception) {
            throw $this->redactor->from($exception, false, AiProviderName::Tavily);
        }

        try {
            $response = $this->client($secret, 30)->post('/search', [
                'query' => 'OpenAI official website',
                'search_depth' => 'basic',
                'max_results' => 1,
                'include_answer' => false,
                'include_raw_content' => false,
                'include_usage' => true,
            ])->throw();
            $reportedCredits = $this->reportedCredits($response->json('usage.credits'));
            $rawRequestId = $this->requestId($response);
            $remaining = $metadata['limit'];
            $used = $metadata['usage'];
            $balanceSummary = is_numeric($remaining) && is_numeric($used)
                ? max(0, (int) $remaining - (int) $used).' credits remaining'
                : null;

            return new ProviderTestResult(
                nativeUnits: max(1, $reportedCredits ?? 1),
                nativeUnit: 'credits',
                costMicros: (int) config('ai.tavily_default_micros_per_credit'),
                costBasis: 'formula_estimate',
                providerRequestId: $rawRequestId,
                balanceSummary: $balanceSummary,
                evidenceSource: $reportedCredits === null ? 'formula_estimate' : 'response',
                providerMetadata: [
                    'usage_credits' => $reportedCredits,
                    'response_time' => $this->boundedResponseTime($response->json('response_time')),
                ],
            );
        } catch (SafeProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->redactor->from($exception, true, AiProviderName::Tavily);
        }
    }

    /**
     * Reconcile a paid/PAYG request through Tavily's bounded request logs.
     * Free plans, missing rows and ambiguous rows intentionally return null;
     * callers must keep the original reservation held in all three cases.
     */
    public function reconcile(string $secret, AiProviderOperation $operation): ?ProviderTestResult
    {
        $requestId = $operation->provider_request_id;
        if ($requestId === null || $requestId === '') {
            return null;
        }

        $start = $operation->started_at ?? $operation->reserved_at ?? $operation->created_at ?? now();
        $end = $operation->finished_at ?? now();
        $windowHours = max(1, (int) config('ai.tavily_logs_window_hours', 24));
        $start = CarbonImmutable::parse($start)->subMinutes(5);
        $end = CarbonImmutable::parse($end)->addMinutes(5);
        if ($end->diffInHours($start) > $windowHours) {
            $end = $start->addHours($windowHours);
        }

        try {
            $response = $this->client($secret, 30)->post('/logs', [
                // Tavily's logs contract accepts inclusive calendar dates.
                // The exact request id is matched locally because the API
                // deliberately exposes no request-id filter.
                'start_date' => $start->utc()->toDateString(),
                'end_date' => $end->utc()->toDateString(),
                'endpoints' => [$this->logEndpoint($operation)],
                'project_id' => $this->projectId(),
                // The API's credential filter is the proof that matching rows
                // belong to this credential; logs intentionally return only a
                // masked key suffix, not a credential id.
                'filter_by_api_key' => true,
                'limit' => 100,
            ]);
            if (in_array($response->status(), [403, 404, 405, 501], true)) {
                return null;
            }
            $response->throw();
            $rows = $this->logRows($response->json());
            $matches = array_values(array_filter($rows, fn (array $row): bool => $this->matchesLog($row, $operation, $requestId)));
            if (count($matches) !== 1) {
                return null;
            }

            $row = $matches[0];
            $credits = $this->reportedCredits(
                $row['credits'] ?? $row['usage_credits'] ?? data_get($row, 'usage.credits'),
            );
            if ($credits === null) {
                return null;
            }
            $price = $this->microsPerCredit($operation);

            return new ProviderTestResult(
                nativeUnits: $credits,
                nativeUnit: 'credits',
                costMicros: $credits * $price,
                costBasis: 'provider_exact',
                providerRequestId: $requestId,
                evidenceSource: 'provider_logs',
                providerMetadata: $this->safeLogMetadata($row),
            );
        } catch (RequestException $exception) {
            // Logs are optional on free accounts. A bounded unavailable logs
            // endpoint is uncertainty, not evidence that the call was free.
            if (in_array($exception->response->status(), [403, 404, 405, 501], true)) {
                return null;
            }
            throw $this->redactor->from($exception, true, AiProviderName::Tavily);
        } catch (Throwable $exception) {
            throw $this->redactor->from($exception, true, AiProviderName::Tavily);
        }
    }

    public function validateSearchRequest(ResearchSearchRequest $request): void
    {
        if (! in_array($request->depth, ['basic', 'fast', 'ultra-fast', 'advanced'], true)) {
            throw new InvalidArgumentException('The research search depth is outside the bounded policy.');
        }
        if (trim($request->query) === '' || strlen($request->query) > (int) config('ai.research.maximum_query_bytes', 4_096)) {
            throw new InvalidArgumentException('The research search query is outside the bounded policy.');
        }
        if (preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', $request->query) === 1) {
            throw new InvalidArgumentException('The research search query contains unsupported control characters.');
        }
        if ($request->maximumResults < 1 || $request->maximumResults > (int) config('ai.research.maximum_results', 10)) {
            throw new InvalidArgumentException('The research result count is outside the bounded policy.');
        }
        $this->validateTimeout($request->timeoutSeconds);
        $this->assertPayloadSize([
            'query' => $request->query,
            'search_depth' => $request->depth,
            'max_results' => $request->maximumResults,
        ]);
    }

    public function validateExtractRequest(ResearchExtractRequest $request): void
    {
        if (! in_array($request->depth, ['basic', 'advanced'], true)
            || $request->urls === []
            || count($request->urls) > (int) config('ai.research.maximum_extract_urls', 20)) {
            throw new InvalidArgumentException('The research extraction request is outside the bounded policy.');
        }
        foreach ($request->urls as $url) {
            if (strlen($url) > (int) config('ai.research.maximum_url_bytes', 2_048) || ! $this->isPublicHttpsUrl($url)) {
                throw new InvalidArgumentException('Research extraction accepts bounded public HTTPS URLs only.');
            }
        }
        $this->validateTimeout($request->timeoutSeconds);
        $this->assertPayloadSize([
            'urls' => $request->urls,
            'extract_depth' => $request->depth,
            'timeout' => $request->timeoutSeconds,
        ]);
    }

    public function search(string $secret, ResearchSearchRequest $request): ResearchResult
    {
        $this->validateSearchRequest($request);
        $credits = $request->depth === 'advanced' ? 2 : 1;

        return $this->request($secret, '/search', [
            'query' => $request->query,
            'search_depth' => $request->depth,
            'max_results' => $request->maximumResults,
            'include_answer' => false,
            'include_raw_content' => false,
            'include_usage' => true,
        ], $credits, $request->timeoutSeconds);
    }

    public function extract(string $secret, ResearchExtractRequest $request): ResearchResult
    {
        $this->validateExtractRequest($request);
        $credits = (int) ceil(count($request->urls) / 5) * ($request->depth === 'advanced' ? 2 : 1);

        return $this->request($secret, '/extract', [
            'urls' => $request->urls,
            'extract_depth' => $request->depth,
            'include_images' => false,
            'include_usage' => true,
            'timeout' => $request->timeoutSeconds,
        ], $credits, $request->timeoutSeconds);
    }

    /** @param array<string, mixed> $payload */
    private function request(string $secret, string $endpoint, array $payload, int $credits, int $timeoutSeconds): ResearchResult
    {
        try {
            $response = $this->client($secret, $timeoutSeconds)->post($endpoint, $payload)->throw();
            $results = $response->json('results', []);
            if (! is_array($results)) {
                throw new SafeProviderException('output_invalid', 'The provider returned an invalid result list.', true);
            }

            $reportedCredits = $this->reportedCredits($response->json('usage.credits'));
            $failedResults = $this->safeFailedResults($response->json('failed_results', []));
            $responseTime = $this->boundedResponseTime($response->json('response_time'));

            return new ResearchResult(
                results: array_values(array_filter($results, 'is_array')),
                credits: $credits,
                providerRequestId: $this->requestId($response),
                reportedCredits: $reportedCredits,
                failedResults: $failedResults,
                responseTime: $responseTime,
                providerMetadata: [
                    'successful_results_count' => count($results),
                    'failed_results_count' => count($failedResults),
                    'reported_credits' => $reportedCredits,
                    'response_time' => $responseTime,
                    'endpoint' => ltrim($endpoint, '/'),
                ],
            );
        } catch (SafeProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->redactor->from($exception, true, AiProviderName::Tavily);
        }
    }

    private function client(string $secret, int $timeout): PendingRequest
    {
        return Http::baseUrl((string) config('ai.providers.tavily.base_url'))
            ->withToken($secret)
            ->withHeaders(['X-Project-ID' => $this->projectId()])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(max(1, $timeout))
            ->withoutRedirecting();
    }

    /** @return array{limit:int|float|string|null,usage:int|float|string|null} */
    private function usageMetadata(string $secret): array
    {
        $cacheKey = 'ai:tavily:usage:'.hash('sha256', $secret);
        $ttl = max(1, (int) config('ai.tavily_usage_cache_seconds', 60));
        $cached = Cache::remember($cacheKey, $ttl, function () use ($secret): array {
            $response = $this->client($secret, 15)->get('/usage')->throw();

            return [
                // Tavily documents key.limit. Keep the old usage_limit as a
                // read-only compatibility fallback for older fixtures.
                'limit' => $response->json('key.limit', $response->json('key.usage_limit')),
                'usage' => $response->json('key.usage'),
            ];
        });

        return ['limit' => $cached['limit'] ?? null, 'usage' => $cached['usage'] ?? null];
    }

    private function projectId(): string
    {
        $project = (string) config('ai.providers.tavily.project_id', config('ai.tavily_project_id', 'outboss-ai'));
        if ($project === '' || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $project) !== 1) {
            return 'outboss-ai';
        }

        return $project;
    }

    private function requestId(mixed $response): ?string
    {
        $value = $response->json('request_id') ?? $response->header('X-Request-Id') ?? $response->header('x-request-id');

        return is_string($value) && $value !== '' ? mb_substr($value, 0, 160) : null;
    }

    private function reportedCredits(mixed $credits): ?int
    {
        return is_numeric($credits) && (int) $credits >= 0 ? (int) $credits : null;
    }

    private function validateTimeout(int $timeout): void
    {
        $minimum = (int) config('ai.research.minimum_timeout_seconds', 1);
        $maximum = (int) config('ai.research.maximum_timeout_seconds', 60);
        if ($timeout < $minimum || $timeout > $maximum) {
            throw new InvalidArgumentException('The research timeout is outside the bounded policy.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertPayloadSize(array $payload): void
    {
        try {
            $bytes = strlen(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The research request cannot be encoded safely.', previous: $exception);
        }
        if ($bytes > (int) config('ai.research.maximum_request_bytes', 16_384)) {
            throw new InvalidArgumentException('The research request exceeds the bounded policy.');
        }
    }

    private function isPublicHttpsUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || $this->hasPrivateSuffix($host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return true;
    }

    private function hasPrivateSuffix(string $host): bool
    {
        foreach (['.localhost', '.local', '.internal', '.test'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function safeFailedResults(mixed $failed): array
    {
        if (! is_array($failed)) {
            return [];
        }
        $safe = [];
        foreach (array_slice($failed, 0, 20) as $item) {
            if (is_string($item)) {
                $safe[] = ['error' => mb_substr($item, 0, 240)];

                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            $row = [];
            foreach (['url', 'status', 'error', 'reason'] as $key) {
                if (isset($item[$key]) && (is_string($item[$key]) || is_int($item[$key]))) {
                    $row[$key] = is_string($item[$key]) ? mb_substr($item[$key], 0, 240) : $item[$key];
                }
            }
            if ($row !== []) {
                $safe[] = $row;
            }
        }

        return $safe;
    }

    private function boundedResponseTime(mixed $time): ?float
    {
        if (! is_numeric($time)) {
            return null;
        }

        return max(0.0, min(86_400.0, (float) $time));
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function logRows(array $response): array
    {
        $rows = $response['logs'] ?? $response['data'] ?? $response['results'] ?? [];
        if (! is_array($rows)) {
            return [];
        }
        if (array_is_list($rows)) {
            $result = [];
            foreach (array_slice($rows, 0, 100) as $row) {
                if (is_array($row)) {
                    $result[] = $row;
                }
            }

            return $result;
        }

        return [$rows];
    }

    /** @param array<string, mixed> $row */
    private function matchesLog(array $row, AiProviderOperation $operation, string $requestId): bool
    {
        $rowRequestId = $row['request_id'] ?? $row['requestId'] ?? $row['id'] ?? null;
        if (! is_string($rowRequestId) || ! hash_equals($requestId, $rowRequestId)) {
            return false;
        }
        $endpoint = strtolower(trim((string) ($row['endpoint'] ?? $row['path'] ?? ''), '/'));
        if ($endpoint === '' || $endpoint !== strtolower(trim($operation->operation, '/'))) {
            return false;
        }
        $project = (string) ($row['project_id'] ?? $row['projectId'] ?? '');
        if ($project !== '' && ! hash_equals($this->projectId(), $project)) {
            return false;
        }
        $credential = $row['credential_id'] ?? $row['key_id'] ?? $row['api_key_id'] ?? null;
        if ($credential !== null && (string) $credential !== (string) $operation->credential_id) {
            return false;
        }

        return true;
    }

    private function microsPerCredit(AiProviderOperation $operation): int
    {
        $reservedUnits = (int) $operation->reserved_native_units;
        if ($reservedUnits > 0 && (int) $operation->reserved_cost_micros > 0) {
            return max(1, intdiv((int) $operation->reserved_cost_micros, $reservedUnits));
        }

        return max(1, (int) config('ai.tavily_default_micros_per_credit', 8_000));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, int|float|string>
     */
    private function safeLogMetadata(array $row): array
    {
        $metadata = [];
        foreach (['request_id', 'endpoint', 'project_id', 'credits', 'usage_credits', 'status', 'response_time'] as $key) {
            if (isset($row[$key]) && (is_string($row[$key]) || is_int($row[$key]) || is_float($row[$key]))) {
                $metadata[$key] = is_string($row[$key]) ? mb_substr($row[$key], 0, 160) : $row[$key];
            }
        }

        return $metadata;
    }

    private function logEndpoint(AiProviderOperation $operation): string
    {
        return ltrim($operation->operation, '/');
    }
}
