<?php

namespace App\Domain\Intelligence\Exceptions;

use RuntimeException;

final class AccountResearchPaused extends RuntimeException
{
    public function __construct(string $message = 'New Account research is temporarily paused.')
    {
        parent::__construct($message, 0);
    }
}
