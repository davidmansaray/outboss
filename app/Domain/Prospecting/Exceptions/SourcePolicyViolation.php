<?php

namespace App\Domain\Prospecting\Exceptions;

use InvalidArgumentException;

final class SourcePolicyViolation extends InvalidArgumentException
{
    public function __construct(string $message, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }
}
