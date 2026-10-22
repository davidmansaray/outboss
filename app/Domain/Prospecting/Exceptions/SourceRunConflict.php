<?php

namespace App\Domain\Prospecting\Exceptions;

use RuntimeException;

final class SourceRunConflict extends RuntimeException
{
    public function __construct(string $message = 'The source run cannot be changed in its current state.')
    {
        parent::__construct($message, 409);
    }
}
