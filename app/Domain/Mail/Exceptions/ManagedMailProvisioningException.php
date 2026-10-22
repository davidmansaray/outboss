<?php

namespace App\Domain\Mail\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Safe application-layer failure for managed-mail orchestration.
 *
 * Provider implementations may expose a more specific safe exception, but
 * an arbitrary provider/runtime exception must never be copied into an API
 * response or persisted as operator-facing error text.
 */
final class ManagedMailProvisioningException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = 'Managed mail provisioning could not be completed.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
