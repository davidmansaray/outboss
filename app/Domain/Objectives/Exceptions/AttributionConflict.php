<?php

namespace App\Domain\Objectives\Exceptions;

use RuntimeException;

final class AttributionConflict extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
