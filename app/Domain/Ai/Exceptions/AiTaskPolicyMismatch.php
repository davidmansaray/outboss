<?php

namespace App\Domain\Ai\Exceptions;

use RuntimeException;

final class AiTaskPolicyMismatch extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The requested generation policy no longer matches the active task policy.');
    }
}
