<?php

namespace App\Domain\Campaigns\Exceptions;

use InvalidArgumentException;
use Throwable;

class InvalidSequenceTemplate extends InvalidArgumentException
{
    public function __construct(
        public readonly string $field,
        public readonly int $stepId,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
