<?php

namespace App\Domain\Intelligence\Exceptions;

use RuntimeException;

final class AccountResearchConflict extends RuntimeException
{
    public function __construct(string $message = 'The research idempotency key was already used for different inputs.')
    {
        parent::__construct($message, 0);
    }
}
