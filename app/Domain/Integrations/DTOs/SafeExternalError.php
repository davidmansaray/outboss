<?php

namespace App\Domain\Integrations\DTOs;

use Carbon\CarbonImmutable;

final readonly class SafeExternalError
{
    public function __construct(
        public string $code,
        public string $message,
        public bool $ambiguous = false,
        public ?CarbonImmutable $retryAfter = null,
        public ?int $httpStatus = null,
    ) {
        if ($code === '' || strlen($code) > 80) {
            throw new \InvalidArgumentException('The external error code is invalid.');
        }
        if ($message === '' || strlen($message) > 500) {
            throw new \InvalidArgumentException('The external error message is invalid.');
        }
    }
}
