<?php

namespace App\Domain\Objectives\Exceptions;

use RuntimeException;

class ApprovalConflict extends RuntimeException
{
    /** @param list<array{code:string,message:string,action_id?:string}> $blockers */
    public function __construct(
        public readonly string $reasonCode,
        public readonly array $blockers = [],
        string $message = 'The exact approval input is no longer current.',
    ) {
        parent::__construct($message, 409);
    }
}
