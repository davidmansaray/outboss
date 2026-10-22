<?php

namespace App\Domain\Conversions\Exceptions;

use RuntimeException;

final class ConversionIdempotencyConflict extends RuntimeException
{
    public function __construct(string $message = 'The conversion delivery key was reused for different content.')
    {
        parent::__construct($message);
    }
}
