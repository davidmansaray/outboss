<?php

namespace App\Domain\Prospecting\Exceptions;

use RuntimeException;

final class SourceIdempotencyConflict extends RuntimeException
{
    public function __construct(string $message = 'The idempotency key was already used for a different request.')
    {
        parent::__construct($message, 409);
    }
}
