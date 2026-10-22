<?php

namespace App\Domain\Mail\Exceptions;

use RuntimeException;

class SendOperationIncomplete extends RuntimeException
{
    public const USER_MESSAGE = 'The message send has not been confirmed. Check its send attempt before trying again.';

    public function __construct()
    {
        parent::__construct(self::USER_MESSAGE);
    }
}
