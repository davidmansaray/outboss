<?php

namespace App\Domain\Mail\DTOs;

/**
 * The result of the database-backed gate used immediately before managed
 * experiment work is leased or sent.
 */
final readonly class MailExperimentRuntimeDecision
{
    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
        public bool $experimentPaused = false,
    ) {}

    public static function allowed(): self
    {
        return new self(true);
    }

    public static function blocked(string $reason, bool $experimentPaused = false): self
    {
        return new self(false, $reason, $experimentPaused);
    }
}
