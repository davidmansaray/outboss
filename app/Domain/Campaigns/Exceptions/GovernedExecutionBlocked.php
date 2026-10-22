<?php

namespace App\Domain\Campaigns\Exceptions;

use RuntimeException;

/** A safe, stable conflict raised when an exact governed checkpoint fails. */
final class GovernedExecutionBlocked extends RuntimeException
{
    /** @param list<array{code:string,message:string,action_id?:string}> $blockers */
    public function __construct(
        public readonly string $reasonCode,
        public readonly array $blockers = [],
    ) {
        parent::__construct('Governed execution is blocked by the current exact checkpoint.');
    }
}
