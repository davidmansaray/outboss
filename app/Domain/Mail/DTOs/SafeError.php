<?php

namespace App\Domain\Mail\DTOs;

readonly class SafeError
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}

    public static function fromProvider(string $code, string $raw): self
    {
        return new self($code, mb_substr(trim(strip_tags($raw)), 0, 180));
    }
}
