<?php

namespace App\Domain\Ai\Exceptions;

use RuntimeException;

class SafeProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        string $safeMessage,
        public readonly bool $ambiguous = false,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?int $httpStatus = null,
        /** @var array<string, bool|int|string|null> */
        public readonly array $safeMetadata = [],
    ) {
        parent::__construct($safeMessage);
    }
}
