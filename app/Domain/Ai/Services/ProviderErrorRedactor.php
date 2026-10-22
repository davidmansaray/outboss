<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Exceptions\SafeProviderException;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

class ProviderErrorRedactor
{
    public function from(
        Throwable $exception,
        bool $requestMayHaveBeenSent = false,
        ?AiProviderName $provider = null,
    ): SafeProviderException {
        if ($exception instanceof SafeProviderException) {
            return $exception;
        }

        if ($exception instanceof ConnectionException) {
            return new SafeProviderException(
                'provider_unavailable',
                'The provider could not be reached safely.',
                $requestMayHaveBeenSent,
            );
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();
            $retryAfter = $exception->response->header('Retry-After');
            $metadata = ['http_status' => $status];

            if ($status === 401 || $status === 403) {
                return new SafeProviderException('invalid_credential', 'The provider rejected this credential.', false, null, $status, $metadata);
            }

            if ($status === 402) {
                return new SafeProviderException(
                    'insufficient_provider_credit',
                    'The provider account cannot accept more paid requests.',
                    false,
                    null,
                    $status,
                    $metadata,
                );
            }

            if ($status === 429) {
                $retryAfterSeconds = $this->retryAfterSeconds($retryAfter);

                return new SafeProviderException(
                    'rate_limited',
                    'The provider is rate limited. Try again after the indicated delay.',
                    false,
                    $retryAfterSeconds,
                    $status,
                    [...$metadata, 'retry_after_seconds' => $retryAfterSeconds],
                );
            }

            if ($status >= 500 || in_array($status, [408, 425], true)) {
                return new SafeProviderException(
                    'provider_unavailable',
                    'The provider did not return a reliable result.',
                    $requestMayHaveBeenSent,
                    null,
                    $status,
                    $metadata,
                );
            }

            if (in_array($status, [400, 413, 422], true)) {
                return new SafeProviderException(
                    'invalid_request',
                    'The provider rejected the bounded request parameters.',
                    false,
                    null,
                    $status,
                    $metadata,
                );
            }

            return new SafeProviderException(
                $provider === AiProviderName::OpenRouter && $status === 409
                    ? 'no_compliant_endpoint'
                    : 'request_rejected',
                'The provider rejected the bounded request.',
                false,
                null,
                $status,
                $metadata,
            );
        }

        return new SafeProviderException(
            'unknown_provider_failure',
            'The provider request failed safely. Try again or contact support.',
            $requestMayHaveBeenSent,
        );
    }

    private function retryAfterSeconds(?string $retryAfter): int
    {
        if (is_numeric($retryAfter)) {
            return max(1, min(86_400, (int) $retryAfter));
        }
        if ($retryAfter !== null) {
            try {
                return max(1, min(86_400, new DateTimeImmutable($retryAfter)->getTimestamp() - time()));
            } catch (Throwable) {
                // Fall through to the bounded default.
            }
        }

        return 60;
    }
}
