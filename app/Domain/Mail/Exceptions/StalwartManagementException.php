<?php

namespace App\Domain\Mail\Exceptions;

use RuntimeException;
use Throwable;

final class StalwartManagementException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = 'The managed mail server could not complete the operation.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
