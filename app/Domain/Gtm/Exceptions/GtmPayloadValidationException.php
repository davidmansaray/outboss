<?php

namespace App\Domain\Gtm\Exceptions;

use InvalidArgumentException;

class GtmPayloadValidationException extends InvalidArgumentException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct('The GTM payload is invalid.');
    }
}
