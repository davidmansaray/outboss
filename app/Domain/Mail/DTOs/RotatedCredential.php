<?php

namespace App\Domain\Mail\DTOs;

/** New app-password material returned by a managed credential rotation. */
readonly class RotatedCredential
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $username,
        public string $password,
        public int|string|null $version = null,
        public array $metadata = [],
    ) {}
}
