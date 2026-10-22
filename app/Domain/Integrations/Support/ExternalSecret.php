<?php

namespace App\Domain\Integrations\Support;

final readonly class ExternalSecret
{
    public function __construct(public string $value) {}

    public function __toString(): string
    {
        return '[redacted]';
    }
}
