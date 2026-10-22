<?php

namespace App\Domain\Objectives\DTOs;

final readonly class ValidatedDraftMessage
{
    /**
     * @param  list<string>  $abstentionReasons
     */
    public function __construct(
        public string $messagePattern,
        public string $evidenceChoice,
        public string $ctaKind,
        public bool $abstain,
        public array $abstentionReasons,
    ) {}
}
