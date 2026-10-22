<?php

namespace App\Domain\Integrations\Exceptions;

use InvalidArgumentException;
use Throwable;

final class CapabilitySchemaViolation extends InvalidArgumentException
{
    /** @param list<string> $paths */
    public function __construct(
        public readonly array $paths,
        string $message = 'The capability payload does not satisfy its schema.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
