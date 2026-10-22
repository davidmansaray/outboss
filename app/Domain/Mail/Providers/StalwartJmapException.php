<?php

namespace App\Domain\Mail\Providers;

use RuntimeException;

/** A redacted, provider-neutral failure from Stalwart's HTTP/JMAP surface. */
class StalwartJmapException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        string $message,
        public readonly string $safeCode = 'stalwart_provider_error',
        public readonly ?int $httpStatus = null,
        public readonly bool $retryable = false,
        public readonly array $metadata = [],
    ) {
        parent::__construct($message);
    }
}
