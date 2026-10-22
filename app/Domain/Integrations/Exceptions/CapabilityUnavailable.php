<?php

namespace App\Domain\Integrations\Exceptions;

use RuntimeException;

final class CapabilityUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $capability,
        public readonly string $reason,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Capability [{$capability}] is unavailable ({$reason}).");
    }
}
