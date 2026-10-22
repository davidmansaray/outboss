<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Conversions\Services\IngestConversionEvent;
use App\Models\ConversionSourceConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use JsonException;

class ConversionWebhookController
{
    public function store(
        Request $request,
        string $publicConnectionId,
        IngestConversionEvent $ingest,
    ): JsonResponse {
        $connection = ConversionSourceConnection::query()
            ->where('public_id', strtolower($publicConnectionId))
            ->where('kind', 'webhook')
            ->where('status', 'active')
            ->with(['workspace', 'currentVersion'])
            ->first();

        // Unknown, disabled and non-webhook public IDs intentionally share the
        // same response so connection state cannot be enumerated.
        if ($connection === null || $connection->currentVersion === null) {
            return $this->reject();
        }

        if ($this->rateLimited($connection)) {
            return $this->reject(429);
        }

        $rawBody = $request->getContent();
        $payloadLimit = (int) $connection->currentVersion->payload_limit_bytes;
        if (strlen($rawBody) > $payloadLimit) {
            return $this->reject(413);
        }

        $signature = $this->signature($request);
        if ($signature === null) {
            return $this->reject();
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return $this->reject(400);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            return $this->reject(400);
        }

        $definition = $decoded['definition_id']
            ?? $decoded['conversion_definition_id']
            ?? $decoded['definition_version_id']
            ?? $decoded['conversion_definition_version_id']
            ?? null;
        if (! is_string($definition) || trim($definition) === '') {
            return $this->reject();
        }

        $source = $decoded['source'] ?? $decoded['source_type'] ?? null;
        if ($source !== null && (! is_string($source) || strtolower(trim($source)) !== 'webhook')) {
            return $this->reject();
        }

        unset(
            $decoded['definition_id'],
            $decoded['conversion_definition_id'],
            $decoded['definition_version_id'],
            $decoded['conversion_definition_version_id'],
            $decoded['source'],
            $decoded['source_type'],
            $decoded['idempotency_key'],
        );

        try {
            $ingest->handle(
                $connection->workspace,
                trim($definition),
                $decoded,
                'webhook',
                $connection,
                null,
                null,
                $rawBody,
                $signature,
            );
        } catch (\Throwable) {
            // Do not expose whether the signature, connection, definition,
            // identity, or payload validation failed.
            return $this->reject();
        }

        $connection->newQuery()->whereKey($connection->id)->update([
            'last_used_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => ['accepted' => true]], 202)
            ->header('Cache-Control', 'no-store');
    }

    private function rateLimited(ConversionSourceConnection $connection): bool
    {
        $policy = $connection->currentVersion?->getAttribute('rate_policy');
        if (is_string($policy)) {
            $policy = json_decode($policy, true);
        }
        $configured = is_array($policy)
            ? ($policy['requests_per_minute'] ?? $policy['per_minute'] ?? $policy['limit'] ?? null)
            : null;
        $limit = is_numeric($configured) ? (int) $configured : 120;
        $limit = max(1, min(100_000, $limit));
        $key = 'conversion-webhook:'.$connection->id;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }

    private function signature(Request $request): ?string
    {
        $signature = null;
        foreach ([
            'X-Outboss-Signature',
            'X-Conversion-Signature',
            'X-Webhook-Signature',
            'X-Hub-Signature-256',
            'X-Signature',
            'Signature',
        ] as $header) {
            $candidate = $request->header($header);
            if (is_string($candidate) && trim($candidate) !== '') {
                $signature = trim($candidate);
                break;
            }
        }
        if ($signature === null) {
            return null;
        }

        $timestamp = null;
        foreach (['X-Outboss-Timestamp', 'X-Conversion-Timestamp', 'X-Webhook-Timestamp', 'X-Signature-Timestamp'] as $header) {
            $candidate = $request->header($header);
            if (is_string($candidate) && preg_match('/^[0-9]{1,12}$/', trim($candidate)) === 1) {
                $timestamp = trim($candidate);
                break;
            }
        }
        if ($timestamp === null || preg_match('/(?:^|,)\s*t\s*=/', $signature) === 1) {
            return $signature;
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $signature) === 1) {
            return 't='.$timestamp.',v1='.$signature;
        }
        if (str_starts_with(strtolower($signature), 'sha256=')) {
            return 't='.$timestamp.','.substr($signature, 0, 7).substr($signature, 7);
        }

        return 't='.$timestamp.','.$signature;
    }

    private function reject(int $status = 401): JsonResponse
    {
        return response()->json([
            'error' => [
                'type' => 'webhook_rejected',
                'message' => 'Webhook was not accepted.',
            ],
        ], $status)->header('Cache-Control', 'no-store');
    }
}
