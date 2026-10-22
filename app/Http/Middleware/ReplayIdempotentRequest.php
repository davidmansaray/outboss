<?php

namespace App\Http\Middleware;

use App\Domain\Api\IdempotencyStore;
use App\Http\Responses\ApiError;
use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReplayIdempotentRequest
{
    public function __construct(private IdempotencyStore $store) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            return $next($request);
        }

        $key = trim($key);

        if (strlen($key) > 255) {
            return ApiError::json(
                'validation_error',
                'The Idempotency-Key header must be 255 characters or fewer.',
                422,
            );
        }

        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        $route = $request->route()?->getName() ?? $request->method().':'.$request->path();
        $fingerprint = $this->fingerprint($request);
        $stored = $this->store->get($token->id, $route, $key);

        if ($stored !== null) {
            if (! hash_equals($stored['fingerprint'], $fingerprint)) {
                return $this->conflict();
            }

            return $this->store->replay($stored);
        }

        return $this->store
            ->lock($token->id, $route, $key)
            ->block(IdempotencyStore::LOCK_WAIT_SECONDS, function () use ($token, $route, $key, $fingerprint, $request, $next): Response {
                $stored = $this->store->get($token->id, $route, $key);

                if ($stored !== null) {
                    if (! hash_equals($stored['fingerprint'], $fingerprint)) {
                        return $this->conflict();
                    }

                    return $this->store->replay($stored);
                }

                $response = $next($request);

                if ($response->getStatusCode() < 500) {
                    $this->store->put($token->id, $route, $key, $fingerprint, $response);
                }

                return $response;
            });
    }

    private function fingerprint(Request $request): string
    {
        return hash('sha256', implode('|', [
            $request->method(),
            '/'.$request->path(),
            $request->getContent(),
        ]));
    }

    private function conflict(): Response
    {
        return ApiError::json(
            'idempotency_conflict',
            'This Idempotency-Key was already used for a different request.',
            409,
        );
    }
}
