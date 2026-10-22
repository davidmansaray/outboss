<?php

namespace App\Domain\Api;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\JsonResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyStore
{
    public const TTL_SECONDS = 86_400;

    public const LOCK_LEASE_SECONDS = 300;

    public const LOCK_WAIT_SECONDS = 60;

    public function __construct(private Repository $cache) {}

    /**
     * @return array{status: int, body: mixed, fingerprint: string}|null
     */
    public function get(int|string $tokenId, string $route, string $key): ?array
    {
        $stored = $this->cache->get($this->cacheKey($tokenId, $route, $key));

        if (! is_array($stored) || ! isset($stored['status'], $stored['body'], $stored['fingerprint'])) {
            return null;
        }

        return [
            'status' => (int) $stored['status'],
            'body' => $stored['body'],
            'fingerprint' => (string) $stored['fingerprint'],
        ];
    }

    public function put(
        int|string $tokenId,
        string $route,
        string $key,
        string $fingerprint,
        Response $response,
    ): void {
        $content = $response->getContent();
        $decoded = is_string($content) && $content !== ''
            ? json_decode($content, true)
            : null;

        $this->cache->put(
            $this->cacheKey($tokenId, $route, $key),
            [
                'status' => $response->getStatusCode(),
                'body' => is_array($decoded) ? $decoded : ['data' => $content],
                'fingerprint' => $fingerprint,
            ],
            self::TTL_SECONDS,
        );
    }

    public function lock(int|string $tokenId, string $route, string $key): Lock
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('The configured cache store does not support atomic locks.');
        }

        return $store->lock(
            'lock:'.$this->cacheKey($tokenId, $route, $key),
            self::LOCK_LEASE_SECONDS,
        );
    }

    /**
     * @param  array{status: int, body: mixed, fingerprint: string}  $stored
     */
    public function replay(array $stored): JsonResponse
    {
        return response()->json($stored['body'], $stored['status']);
    }

    private function cacheKey(int|string $tokenId, string $route, string $key): string
    {
        return 'api:idempotency:'.hash('sha256', $tokenId.'|'.$route.'|'.$key);
    }
}
