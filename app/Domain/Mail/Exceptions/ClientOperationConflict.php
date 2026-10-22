<?php

namespace App\Domain\Mail\Exceptions;

use RuntimeException;

class ClientOperationConflict extends RuntimeException
{
    public const USER_MESSAGE = 'This client operation ID was already used for a different message.';

    public function __construct()
    {
        parent::__construct('client_operation_id_conflict');
    }
}
