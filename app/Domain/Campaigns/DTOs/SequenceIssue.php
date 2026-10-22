<?php

namespace App\Domain\Campaigns\DTOs;

final readonly class SequenceIssue
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}
}
